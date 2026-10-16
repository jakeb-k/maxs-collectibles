<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\PricingService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    private function products(int $price, int $discount = 0): Collection
    {
        $product = Product::factory()->make(['id' => 1, 'price_minor' => $price, 'discount_bps' => $discount]);
        $product->setRelation('options', collect()); $product->setRelation('images', collect());
        return collect([1 => $product]);
    }

    public static function moneyFixtures(): array
    {
        $fixtures = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/pricing.json'), true, 512, JSON_THROW_ON_ERROR);
        return array_map(fn ($fixture) => array_values($fixture), $fixtures);
    }

    #[DataProvider('moneyFixtures')]
    public function test_exact_shared_rounding_fixtures(int $price, int $productBps, int $promoBps, string $mode, int $rate, int $quantity, int $delivery, int $net, int $tax, int $payable, int $total, int $taxTotal): void
    {
        config(['commerce.tax_mode' => $mode, 'commerce.delivery_tax_mode' => $mode, 'commerce.tax_rate_bps' => $rate,
            'commerce.delivery_minor' => $delivery, 'commerce.promo_codes' => ['FIXTURE' => ['discount_bps' => $promoBps]]]);
        $quote = app(PricingService::class)->quote([['product_id' => 1, 'quantity' => $quantity, 'selection' => []]], $this->products($price, $productBps), 'FIXTURE');
        $this->assertSame($net, $quote['lines'][0]['net_unit_minor']);
        $this->assertSame($tax, $quote['lines'][0]['tax_unit_minor']);
        $this->assertSame($payable, $quote['lines'][0]['payable_unit_minor']);
        $this->assertSame($total, $quote['totals']['total_minor']);
        $this->assertSame($taxTotal, $quote['totals']['tax_minor']);
        $this->assertSame($total, $payable * $quantity + $quote['delivery']['payable_minor']);
    }

    public static function providerBounds(): array
    {
        return [[0, false], [49, false], [50, true], [99999999, true], [100000000, false]];
    }

    #[DataProvider('providerBounds')]
    public function test_provider_total_bounds_are_checked_without_clamping(int $amount, bool $accepted): void
    {
        if (!$accepted) $this->expectException(ValidationException::class);
        $quote = app(PricingService::class)->quote([['product_id' => 1, 'quantity' => 1, 'selection' => []]], $this->products($amount));
        if ($accepted) $this->assertSame($amount, $quote['totals']['total_minor']);
    }

    public function test_invalid_expired_promo_and_unreviewed_configuration_fail_explicitly(): void
    {
        $pricing = app(PricingService::class);
        config(['commerce.promo_codes' => ['OLD' => ['discount_bps' => 1000, 'expires_at' => now()->subSecond()->toIso8601String()]]]);
        foreach (['UNKNOWN', 'OLD'] as $code) {
            try { $pricing->quote([['product_id' => 1, 'quantity' => 1, 'selection' => []]], $this->products(1000), $code); $this->fail('Invalid promo accepted'); }
            catch (ValidationException $error) { $this->assertArrayHasKey('promo_code', $error->errors()); }
        }
        foreach (['currency' => 'USD', 'tax_mode' => null, 'delivery_minor' => null, 'tax_rate_bps' => -1, 'payment_methods' => ['zip'], 'delivery_tax_mode' => 'exclusive'] as $key => $invalid) {
            $before = config('commerce.'.$key); config(['commerce.'.$key => $invalid]);
            try { $pricing->rules(); $this->fail('Unreviewed configuration accepted: '.$key); }
            catch (\LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
            finally { config(['commerce.'.$key => $before]); }
        }
    }

    public function test_large_quantity_is_exact_and_rejected_at_provider_bound_and_hash_order_is_stable(): void
    {
        $pricing = app(PricingService::class);
        $this->assertSame($pricing->fingerprint(['b' => 2, 'a' => ['z' => 3, 'y' => 4]]), $pricing->fingerprint(['a' => ['y' => 4, 'z' => 3], 'b' => 2]));
        $this->expectException(ValidationException::class);
        $pricing->quote([['product_id' => 1, 'quantity' => 999999, 'selection' => []]], $this->products(999999999));
    }
}
