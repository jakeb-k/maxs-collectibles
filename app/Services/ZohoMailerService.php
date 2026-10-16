<?php

namespace App\Services;

use Illuminate\Support\Facades\{DB, Http};

/** Throws on rejected or unknown handoff; never returns an error body as success. */
class ZohoMailerService
{
    public function sendMail(string $to, string $subject, string $html): bool|string
    {
        $account = config('services.zoho.account_id'); $base = config('services.zoho.mail_api_url');
        $token = DB::table('zoho_oauth_tokens')->latest('id')->value('access_token'); $from = config('mail.from.address');
        $hosts = ['mail.zoho.com', 'mail.zoho.eu', 'mail.zoho.in', 'mail.zoho.com.au', 'mail.zoho.jp', 'mail.zohocloud.ca', 'mail.zoho.com.cn', 'mail.zoho.ae', 'mail.zoho.sa'];
        if (!is_string($account) || !preg_match('/^[A-Za-z0-9_-]+$/', $account) || !is_string($base)
            || !in_array($base, array_map(fn ($host) => 'https://'.$host, $hosts), true)
            || !is_string($token) || $token === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Zoho mail configuration/token is incomplete.');
        }
        $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$token])->acceptJson()->connectTimeout(10)->timeout(25)
            ->post($base.'/api/accounts/'.$account.'/messages', ['fromAddress' => $from, 'toAddress' => $to, 'subject' => $subject,
                'content' => $html, 'mailFormat' => 'html', 'encoding' => 'UTF-8', 'askReceipt' => 'no']);
        $code = $response->json('status.code'); $id = $response->json('data.messageId');
        if (!$response->successful() || $code !== 200 || !is_string($id) || $id === '') {
            throw new \RuntimeException('Zoho handoff rejected or unknown (HTTP '.$response->status().', API '.(is_int($code) ? $code : 'unknown').').');
        }
        return $id;
    }
}
