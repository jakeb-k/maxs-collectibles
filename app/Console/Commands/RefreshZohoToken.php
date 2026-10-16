<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Http};

class RefreshZohoToken extends Command
{
    protected $signature = 'zoho:refresh-token';
    protected $description = 'Refresh configured Zoho OAuth token without exposing credentials';

    public function handle(): int
    {
        $record = DB::table('zoho_oauth_tokens')->latest('id')->first();
        $url = config('services.zoho.oauth_url');
        $allowed = ['com', 'eu', 'in', 'com.au', 'jp', 'com.cn', 'ae', 'sa'];
        $urls = array_map(fn ($suffix) => 'https://accounts.zoho.'.$suffix.'/oauth/v2/token', $allowed);
        $urls[] = 'https://accounts.zohocloud.ca/oauth/v2/token';
        if (!$record || !$record->refresh_token || !in_array($url, $urls, true) || !config('services.zoho.client_id') || !config('services.zoho.client_secret')) {
            $this->error('Zoho OAuth configuration/token is incomplete.'); return self::FAILURE;
        }
        try {
            $response = Http::asForm()->connectTimeout(10)->timeout(25)->post($url, ['grant_type' => 'refresh_token',
                'client_id' => config('services.zoho.client_id'), 'client_secret' => config('services.zoho.client_secret'), 'refresh_token' => $record->refresh_token]);
            $data = $response->json();
            if (!$response->successful() || !is_array($data) || isset($data['error']) || !is_string($data['access_token'] ?? null) || $data['access_token'] === ''
                || !is_numeric($data['expires_in'] ?? null) || (int) $data['expires_in'] <= 0) throw new \RuntimeException;
            DB::table('zoho_oauth_tokens')->insert(['access_token' => $data['access_token'], 'refresh_token' => $record->refresh_token,
                'expires_in' => now()->addSeconds((int) $data['expires_in'])->toIso8601String(), 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $error) { $this->error('Zoho token refresh failed; credentials were not logged.'); return self::FAILURE; }
        $this->info('Zoho access token refreshed.'); return self::SUCCESS;
    }
}
