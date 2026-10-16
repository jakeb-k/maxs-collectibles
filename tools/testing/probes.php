<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
Route::middleware('web')->group(function () {
    Route::get('/__test/session', function (Request $request) {
        if ($request->query('sentinel')) $request->session()->put('sentinel', $request->query('sentinel'));
        return response()->json(['user'=>Auth::id(), 'sentinel'=>$request->session()->get('sentinel'), 'csrf'=>csrf_token()]);
    });
});

// Synthetic illustration media stays inside the current isolated run.
config(['filesystems.disks.public.url' => '/__test/media']);
Route::get('/__test/media/{filename}', function (string $filename) {
    App\Support\TestEnvironment::verifyDatabase();
    abort_unless(preg_match('/^fixture-[1-5]-[01]\.png$/D', $filename), 404);
    $base = storage_path('app/public');
    $file = $base.'/'.$filename;
    abort_if(is_link($base) || is_link($file), 404);
    $real = realpath($file);
    abort_unless($real && str_starts_with($real, $base.'/') && is_file($real), 404);
    return response()->file($real, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
});

// Required only from already gated testing probes. No local payment state bypass.
App\Support\TestEnvironment::verifyDatabase();
app()->bind(App\Contracts\PaymentGateway::class, function () {
    App\Support\TestEnvironment::verifyDatabase();
    abort_if(is_link(storage_path('fake-remote.json')) || is_link(storage_path('next-gateway-behavior')), 500);
    return new class(storage_path('fake-remote.json')) extends Tests\Fakes\FakePaymentGateway {
        public function createSession(array $payload, string $idempotencyKey): array {
            $behavior = storage_path('next-gateway-behavior');
            if (is_file($behavior)) { $this->createBehaviors = [file_get_contents($behavior)]; unlink($behavior); }
            return parent::createSession($payload, $idempotencyKey);
        }
    };
});
Illuminate\Support\Facades\Route::middleware('web')->post('/__test/commerce', function (Illuminate\Http\Request $request) {
    App\Support\TestEnvironment::verifyDatabase();
    abort_if(is_link(storage_path('fake-remote.json')) || is_link(storage_path('next-gateway-behavior')), 500);
    $data = $request->validate(['action' => ['required', Illuminate\Validation\Rule::in(['product','behavior','paid','inspect'])], 'product_id' => ['sometimes','integer'], 'price_minor' => ['sometimes','integer','min:50'], 'stock_quantity' => ['sometimes','integer','min:0'], 'available' => ['sometimes','boolean'], 'delete' => ['sometimes','boolean'], 'restore' => ['sometimes','boolean'], 'name' => ['sometimes','string','max:100'], 'behavior' => ['sometimes',Illuminate\Validation\Rule::in(['success','timeout_after_create','cached_500'])], 'attempt_key' => ['sometimes','uuid']]);
    if ($data['action'] === 'product') {
        $product = App\Models\Product::withTrashed()->where('sku','like','FIXTURE-%')->findOrFail($data['product_id']);
        $service = app(App\Services\ProductAuthoringService::class);
        if (($data['restore'] ?? false) && $product->trashed()) Illuminate\Support\Facades\DB::transaction(function () use ($product) { $locked = app(App\Services\StockAllocationService::class)->lockProducts([$product->id])->get($product->id); $locked->restore(); });
        $product->refresh();
        if ($data['delete'] ?? false) $service->delete($product);
        else {
            $dto = app(App\Services\CatalogueService::class)->dto($product->load(['tags','images','options']), true);
            $payload = array_merge($dto, array_intersect_key($data, array_flip(['price_minor','stock_quantity','available','name'])), ['kept_images' => $product->images->map(fn ($image) => ['id' => $image->id, 'alt_text' => $image->alt_text, 'caption' => $image->caption])->all()]);
            $service->save($product, $payload, []);
        }
        return response()->json(['ok' => true]);
    }
    if ($data['action'] === 'behavior') { file_put_contents(storage_path('next-gateway-behavior'), $data['behavior']); return response()->json(['ok'=>true]); }
    $attempt = App\Models\CheckoutAttempt::where('key', $data['attempt_key'])->firstOrFail();
    $resume = $request->session()->get('checkout_resume.'.$attempt->key);
    abort_unless($resume && hash_equals($resume['owner_hash'], $attempt->owner_hash), 403);
    $identity = $request->user() ? 'user:'.$request->user()->id : 'guest:'.$request->session()->get('checkout_identity', '');
    abort_unless(hash_equals($attempt->owner_hash, hash_hmac('sha256', $identity, config('app.key'))), 403);
    if ($data['action'] === 'paid') {
        $file = storage_path('fake-remote.json');
        $gateway = new Tests\Fakes\FakePaymentGateway($file);
        abort_unless(isset($gateway->sessions[$attempt->idempotency_key]), 409);
        $gateway->sessions[$attempt->idempotency_key] = $gateway->paidSession($gateway->sessions[$attempt->idempotency_key]);
        file_put_contents($file, json_encode($gateway->sessions, JSON_THROW_ON_ERROR));
    }
    return response()->json(['attempt_key'=>$attempt->key,'state'=>$attempt->state,'order_id'=>$attempt->order_id,'order_status'=>$attempt->order->status,'attempt_count'=>App\Models\CheckoutAttempt::where('owner_hash',$attempt->owner_hash)->count(),'order_count'=>App\Models\CheckoutAttempt::where('owner_hash',$attempt->owner_hash)->distinct()->count('order_id')]);
});

// Private test-only delivery-link inspection. Never expose payloads/order tokens.
Illuminate\Support\Facades\Route::middleware('web')->post('/__test/account-mail', function (Illuminate\Http\Request $request) {
    App\Support\TestEnvironment::verifyDatabase();
    $data = $request->validate(['email'=>['required',Illuminate\Validation\Rule::in(['browser-account@example.test','browser-subscriber@example.test','isolation@example.test'])], 'kind'=>['required',Illuminate\Validation\Rule::in(['password_reset','verification','welcome'])]]);
    $delivery = App\Models\NotificationDelivery::where('recipient',$data['email'])->where('type',$data['kind'])->latest('id')->firstOrFail();
    $payload = $delivery->payload;
    preg_match_all('/href="([^"]+)"/', $payload['html'], $matches);
    $paths = ['password_reset'=>'~^/reset-password/[A-Za-z0-9]+$~D', 'verification'=>'~^/verify-email/[0-9]+/[a-f0-9]{40}$~D', 'welcome'=>'~^/unsubscribe$~D'];
    $link = collect($matches[1])->map(fn ($url)=>html_entity_decode($url,ENT_QUOTES))->first(fn ($url)=>preg_match($paths[$data['kind']],parse_url($url,PHP_URL_PATH) ?: ''));
    abort_unless($link && parse_url($link,PHP_URL_SCHEME)===parse_url(config('app.url'),PHP_URL_SCHEME) && parse_url($link,PHP_URL_HOST)===parse_url(config('app.url'),PHP_URL_HOST) && parse_url($link,PHP_URL_PORT)===parse_url(config('app.url'),PHP_URL_PORT), 404);
    return response()->json(['link'=>$link])->header('Cache-Control','private, no-store');
});

Illuminate\Support\Facades\Route::get('/__test/media/products/{directory}/{filename}', function (string $directory, string $filename) {
    App\Support\TestEnvironment::verifyDatabase();
    abort_unless(preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$directory)
        && preg_match('/^(original\.(jpg|jpeg|png|webp)|(480|960|1600)\.webp)$/D',$filename),404);
    $base=storage_path('app/public'); $path=$base.'/products/'.$directory.'/'.$filename;
    foreach ([$base,$base.'/products',$base.'/products/'.$directory,$path] as $part) abort_if(is_link($part),404);
    $real=realpath($path); abort_unless($real && str_starts_with($real,$base.'/products/'.$directory.'/') && is_file($real),404);
    return response()->file($real,['Cache-Control'=>'no-store']);
});
