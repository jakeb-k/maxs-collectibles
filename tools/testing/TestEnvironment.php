<?php
namespace App\Support;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
final class TestEnvironment
{
    public static function verifyDatabase(): void
    {
        maxs_test_guard();
        $connection = config('database.connections.mysql');
        foreach (['host'=>'mysql','port'=>'3306','database'=>'maxs_test','username'=>'maxs_test'] as $key=>$value) {
            if ((string)($connection[$key] ?? '') !== $value) throw new \RuntimeException("Unsafe configured DB $key");
        }
        $identity=DB::selectOne('SELECT identity, @@hostname AS hostname, DATABASE() AS db, CURRENT_USER() AS dbuser FROM maxs_guard.sentinel');
        if (!$identity || $identity->identity !== 'maxs-isolated-v1' || $identity->hostname !== 'maxs-test-mysql' || $identity->db !== 'maxs_test' || $identity->dbuser !== 'maxs_test@%') throw new \RuntimeException('Database sentinel mismatch');
    }
    public static function fakeProviders(): void
    {
        Http::preventStrayRequests();
        Http::fake(['mail.zoho.com.au/*'=>Http::response(['status'=>['code'=>200]]), 'accounts.zoho.com.au/*'=>Http::response(['access_token'=>'fake'])]);
        Mail::fake();
        \Stripe\ApiRequestor::setHttpClient(new class implements \Stripe\HttpClient\ClientInterface {
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1') { throw new \RuntimeException('Unscripted Stripe call forbidden: '.$method); }
        });
        app()->bind(\App\Services\ZohoMailerService::class, fn()=>new class extends \App\Services\ZohoMailerService {
            public function __construct() {}
            public function sendMail(string $to, string $subject, string $html): bool|string { return true; }
        });
    }
}
