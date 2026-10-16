<?php

namespace App\Jobs;

use App\Models\{NotificationDelivery, SubscriberEmail, User};
use App\Services\ZohoMailerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB, Log, Password};
use Illuminate\Support\Str;

/** One delivery authority; retries are bounded durably, not reset by duplicate queue jobs. */
class SendNotificationDelivery implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 45;
    public bool $failOnTimeout = true;
    public const MAX_ATTEMPTS = 5;
    public const BACKOFF = [60, 300, 900, 3600, 3600];

    public function __construct(public int $deliveryId, public ?string $queuedLease = null) { $this->afterCommit(); }

    public static function enqueue(int $id, bool $retry = false): bool
    {
        return DB::transaction(function () use ($id, $retry) {
            $row = NotificationDelivery::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($row->state === 'sent' || ($row->lease_expires_at && $row->lease_expires_at->isFuture())) return false;
            if (!$retry && $row->attempts >= self::MAX_ATTEMPTS) {
                if ($row->state === 'sending') $row->update(['state' => 'failed', 'last_error' => 'retry_budget_exhausted_unknown_handoff', 'lease_token' => null, 'lease_expires_at' => null, 'next_retry_at' => null]);
                return false;
            }
            if (!$retry && $row->next_retry_at && $row->next_retry_at->isFuture()) return false;
            $lease = (string) Str::uuid();
            $row->update(['state' => 'sending', 'lease_token' => $lease, 'lease_expires_at' => now()->addMinutes(2),
                'last_error' => 'queued', 'attempts' => $retry ? 0 : $row->attempts, 'next_retry_at' => null]);
            self::dispatch($id, $lease)->afterCommit();
            return true;
        });
    }

    public function handle(ZohoMailerService $mailer): void
    {
        $claim = DB::transaction(function () {
            $row = NotificationDelivery::whereKey($this->deliveryId)->lockForUpdate()->firstOrFail();
            if ($row->state === 'sent' || $row->attempts >= self::MAX_ATTEMPTS) return null;
            if ($this->queuedLease !== null) {
                if ($row->state !== 'sending' || $row->lease_token !== $this->queuedLease || $row->last_error !== 'queued'
                    || !$row->lease_expires_at?->isFuture()) return null;
            } elseif (($row->lease_expires_at && $row->lease_expires_at->isFuture()) || ($row->next_retry_at && $row->next_retry_at->isFuture())) return null;
            try { $payload = $row->payload; }
            catch (\Throwable $error) {
                $attempt = $row->attempts + 1;
                $row->update(['state' => 'failed', 'attempts' => $attempt, 'last_error' => 'payload_unreadable',
                    'lease_token' => null, 'lease_expires_at' => null,
                    'next_retry_at' => $attempt < self::MAX_ATTEMPTS ? now()->addSeconds(self::BACKOFF[$attempt - 1]) : null]);
                return [$row, null];
            }
            $invalid = null;
            if ($row->type === 'welcome' && !SubscriberEmail::whereKey($payload['subscription_id'] ?? 0)->where('email', $row->recipient)->exists()) $invalid = 'subscription_removed';
            if (in_array($row->type, ['password_reset', 'verification'], true)) {
                $user = User::whereKey($payload['user_id'] ?? 0)->where('email', $row->recipient)->first();
                if (!$user || ($payload['expires_at'] ?? 0) <= now()->timestamp) $invalid = 'account_link_expired_or_changed';
                elseif ($row->type === 'password_reset' && !Password::broker()->tokenExists($user, $payload['reset_token'] ?? '')) $invalid = 'reset_token_replaced';
                elseif ($row->type === 'verification' && $user->hasVerifiedEmail()) $invalid = 'already_verified';
            }
            if ($invalid) { $row->update(['state' => 'failed', 'attempts' => self::MAX_ATTEMPTS, 'last_error' => $invalid, 'lease_token' => null, 'lease_expires_at' => null, 'next_retry_at' => null]); return null; }
            $lease = $this->queuedLease ?? (string) Str::uuid();
            // Rewrap legacy pre-encryption intents when encountered; new intents are always encrypted.
            $row->update(['payload' => $payload, 'state' => 'sending', 'attempts' => $row->attempts + 1, 'lease_token' => $lease,
                'lease_expires_at' => now()->addMinutes(2), 'last_error' => null]);
            return [$row, $lease];
        });
        if (!$claim) return;
        [$row, $lease] = $claim;
        if ($lease === null) throw new \RuntimeException('Notification delivery '.$row->id.' payload unavailable; restore encryption keys.');
        try {
            if (DB::transactionLevel() !== 0) throw new \LogicException('Mail cannot be sent inside a transaction.');
            $payload = $row->payload;
            $receipt = $mailer->sendMail($row->recipient, $payload['subject'], $payload['html']);
            if ($receipt === false || $receipt === '') throw new \RuntimeException('Mail handoff was not accepted.');
            NotificationDelivery::whereKey($row->id)->where('lease_token', $lease)->where('state', 'sending')
                ->update(['state' => 'sent', 'sent_at' => now(), 'lease_token' => null, 'lease_expires_at' => null, 'next_retry_at' => null, 'last_error' => null, 'updated_at' => now()]);
        } catch (\Throwable $error) {
            NotificationDelivery::whereKey($row->id)->where('lease_token', $lease)->where('state', 'sending')
                ->update(['state' => 'failed', 'last_error' => 'handoff_rejected_or_unknown:'.class_basename($error), 'lease_token' => null, 'lease_expires_at' => null,
                    'next_retry_at' => $row->attempts < self::MAX_ATTEMPTS ? now()->addSeconds(self::BACKOFF[$row->attempts - 1]) : null, 'updated_at' => now()]);
            Log::warning('Notification handoff rejected or unknown', ['delivery_id' => $row->id, 'attempt' => $row->attempts]);
            // Do not serialize provider exceptions (which can contain request payloads/credentials) into failed_jobs.
            throw new \RuntimeException('Notification delivery '.$row->id.' failed; handoff rejected or unknown.');
        }
    }

    public function failed(?\Throwable $error): void
    {
        // A killed worker can leave a leased row; scheduler recovers it after expiry, with a new fence.
        Log::warning('Notification queue job failed; inspect durable delivery state', ['delivery_id' => $this->deliveryId]);
    }
}
