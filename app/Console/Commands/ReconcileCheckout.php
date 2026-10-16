<?php

namespace App\Console\Commands;

use App\Models\CheckoutAttempt;
use App\Services\CheckoutSessionService;
use Illuminate\Console\Command;

class ReconcileCheckout extends Command
{
    protected $signature = 'checkout:reconcile {--attempt= : Local attempt ID or UUID}';
    protected $description = 'Reconcile uncertain Checkout sessions without extending expiry or releasing unproven holds';

    public function handle(CheckoutSessionService $sessions): int
    {
        app(\App\Services\PaymentEventService::class)->retryPending();
        $query = CheckoutAttempt::query();
        if ($identity = $this->option('attempt')) $query->where(fn ($q) => $q->where('key', $identity)->when(ctype_digit((string) $identity), fn ($q) => $q->orWhere('id', (int) $identity)));
        else $query->whereNotIn('state', ['released', 'rejected', 'paid', 'completed'])->where(fn ($q) => $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()));
        $failures = 0;
        $query->orderBy('id')->chunkById(100, function ($attempts) use ($sessions, &$failures) {
            foreach ($attempts as $attempt) {
                try { $result = $sessions->reconcile($attempt->id); $this->line('Attempt '.$attempt->id.': '.$result->state); }
                catch (\Throwable $error) { $failures++; $this->error('Attempt '.$attempt->id.': reconciliation failed ('.class_basename($error).')'); }
            }
        });
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
