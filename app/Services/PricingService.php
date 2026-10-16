<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Sole integer money authority for catalogue, quotes, snapshots and provider amounts. */
class PricingService
{
    public function rules(): array
    {
        $rules = config('commerce');
        if (($rules['currency'] ?? null) !== 'AUD' || !is_string($rules['rule_version'] ?? null) || $rules['rule_version'] === ''
            || !in_array($rules['tax_mode'] ?? null, ['none', 'inclusive', 'exclusive'], true)
            || ($rules['delivery_tax_mode'] ?? null) !== $rules['tax_mode']
            || ($rules['payment_methods'] ?? null) !== ['card']) {
            throw new \LogicException('Reviewed AUD tax, delivery, payment and rule-version settings are required.');
        }
        foreach (['tax_rate_bps' => 10000, 'delivery_minor' => 99999999] as $key => $max) {
            if (!(is_int($rules[$key] ?? null) || (is_string($rules[$key] ?? null) && ctype_digit($rules[$key])))
                || (int) $rules[$key] < 0 || (int) $rules[$key] > $max) {
                throw new \LogicException('Invalid commercial setting: '.$key);
            }
            $rules[$key] = (int) $rules[$key];
        }
        if (!is_array($rules['promo_codes'] ?? null)) throw new \LogicException('Invalid configured promo codes.');
        return array_intersect_key($rules, array_flip(['currency', 'rule_version', 'tax_mode', 'tax_rate_bps', 'delivery_minor', 'delivery_tax_mode', 'payment_methods', 'promo_codes']));
    }

    private function halfUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    public function unit(int $price, int $productBps = 0, int $promoBps = 0, ?array $rules = null): array
    {
        $rules ??= $this->rules();
        if ($price < 0 || $price > 999999999 || $productBps < 0 || $productBps > 10000 || $promoBps < 0 || $promoBps > 10000) {
            throw ValidationException::withMessages(['items' => 'An item has unsupported canonical money values.']);
        }
        $afterProduct = $this->halfUp($price * (10000 - $productBps), 10000);
        $net = $this->halfUp($afterProduct * (10000 - $promoBps), 10000);
        $tax = match ($rules['tax_mode']) {
            'exclusive' => $this->halfUp($net * $rules['tax_rate_bps'], 10000),
            'inclusive' => $this->halfUp($net * $rules['tax_rate_bps'], 10000 + $rules['tax_rate_bps']),
            'none' => 0,
        };
        return ['original_unit_minor' => $price, 'product_discount_unit_minor' => $price - $afterProduct,
            'promo_discount_unit_minor' => $afterProduct - $net, 'net_unit_minor' => $net,
            'tax_unit_minor' => $tax, 'payable_unit_minor' => $net + ($rules['tax_mode'] === 'exclusive' ? $tax : 0)];
    }

    public function display(Product $product): array
    {
        try {
            $rules = $this->rules();
            if ($product->currency !== $rules['currency']) throw new \LogicException('Unsupported item currency.');
            $unit = $this->unit($product->price_minor, $product->discount_bps, 0, $rules);
            return ['pricing_configured' => true, 'display_unit_minor' => $unit['payable_unit_minor'],
                'pricing' => $unit, 'tax_mode' => $rules['tax_mode'], 'rule_version' => $rules['rule_version']];
        } catch (\LogicException|ValidationException $error) {
            return ['pricing_configured' => false, 'display_unit_minor' => null, 'pricing' => null, 'tax_mode' => null, 'rule_version' => null];
        }
    }

    public function promoBps(?string $code, array $rules): int
    {
        if ($code === null || $code === '') return 0;
        $promotion = $rules['promo_codes'][$code] ?? null;
        if (!$promotion || !is_array($promotion)) throw ValidationException::withMessages(['promo_code' => 'This promo code is invalid or expired.']);
        $bps = $promotion['discount_bps'] ?? null;
        if (!is_int($bps) || $bps < 0 || $bps > 10000) throw new \LogicException('Invalid configured promo discount.');
        if (isset($promotion['expires_at'])) {
            try { $expires = \Carbon\CarbonImmutable::parse($promotion['expires_at']); }
            catch (\Throwable $error) { throw new \LogicException('Invalid configured promo expiry.'); }
            if (now()->greaterThanOrEqualTo($expires)) throw ValidationException::withMessages(['promo_code' => 'This promo code is invalid or expired.']);
        }
        return $bps;
    }

    /** Input is normalized by CheckoutRequest; product reads can be locked by the caller. */
    public function quote(array $items, Collection $products, ?string $promoCode = null): array
    {
        $rules = $this->rules();
        $promo = $this->promoBps($promoCode, $rules);
        if (count($items) < 1 || count($items) > 99) throw ValidationException::withMessages(['items' => 'Use between one and 99 item selections.']);
        $lines = [];
        $totals = ['subtotal_minor' => 0, 'discount_minor' => 0, 'net_minor' => 0, 'tax_minor' => 0, 'items_payable_minor' => 0];
        foreach ($items as $index => $item) {
            $product = $products->get($item['product_id']);
            if (!$product || $product->trashed()) throw ValidationException::withMessages(["items.$index.product_id" => 'This item is unavailable.']);
            if ($product->currency !== 'AUD') throw ValidationException::withMessages(['items' => 'Only AUD items are supported.']);
            $quantity = $item['quantity'];
            if (!is_int($quantity) || $quantity < 1 || $quantity > 999999) throw ValidationException::withMessages(["items.$index.quantity" => 'Use a positive integer quantity up to 999999.']);
            $selection = $item['selection'];
            $product->loadMissing(['options', 'images']);
            $definitions = $product->options->keyBy('type');
            if (array_diff(array_keys($selection), $definitions->keys()->all()) || count($selection) !== $definitions->count()) {
                throw ValidationException::withMessages(["items.$index.selection" => 'Choose exactly the available item options.']);
            }
            foreach ($definitions as $type => $option) {
                if (!array_key_exists($type, $selection) || !in_array($selection[$type], $option->values, true)) {
                    throw ValidationException::withMessages(["items.$index.selection" => 'An item option is invalid. Refresh the item.']);
                }
            }
            $unit = $this->unit($product->price_minor, $product->discount_bps, $promo, $rules);
            $image = $product->images->sortBy('position')->first();
            $line = ['product_id' => $product->id, 'quantity' => $quantity, 'title' => $product->name,
                'description' => $product->description, 'facts' => $product->facts ?? [], 'selected_options' => $selection,
                'image_path' => $image?->file_path, 'image_disk' => $image?->disk, 'image_alt' => $image?->alt_text,
                'image_url' => $image && !str_contains($image->file_path, '://') ? \Illuminate\Support\Facades\Storage::disk($image->disk)->url($image->file_path) : null] + $unit;
            $line['line_payable_minor'] = $unit['payable_unit_minor'] * $quantity;
            $lines[] = $line;
            $totals['subtotal_minor'] += $unit['original_unit_minor'] * $quantity;
            $totals['discount_minor'] += ($unit['original_unit_minor'] - $unit['net_unit_minor']) * $quantity;
            $totals['net_minor'] += $unit['net_unit_minor'] * $quantity;
            $totals['tax_minor'] += $unit['tax_unit_minor'] * $quantity;
            $totals['items_payable_minor'] += $line['line_payable_minor'];
        }
        $deliveryUnit = $this->unit($rules['delivery_minor'], 0, 0, $rules);
        $delivery = ['net_minor' => $deliveryUnit['net_unit_minor'], 'tax_minor' => $deliveryUnit['tax_unit_minor'], 'payable_minor' => $deliveryUnit['payable_unit_minor']];
        $totals['tax_minor'] += $delivery['tax_minor'];
        $totals['delivery_minor'] = $delivery['payable_minor'];
        $totals['total_minor'] = $totals['items_payable_minor'] + $delivery['payable_minor'];
        // Pinned PaymentIntent amount contract: eight digits. AUD settlement minimum: 50 cents.
        if ($totals['total_minor'] < 50 || $totals['total_minor'] > 99999999) {
            throw ValidationException::withMessages(['items' => 'The payable total must be between AUD 0.50 and AUD 999,999.99.']);
        }
        return ['currency' => $rules['currency'], 'rule_version' => $rules['rule_version'], 'rules_fingerprint' => $this->fingerprint($rules),
            'tax_mode' => $rules['tax_mode'], 'promo_code' => $promoCode, 'promo_bps' => $promo, 'lines' => $lines, 'delivery' => $delivery, 'totals' => $totals];
    }

    public function fingerprint(array $value): string
    {
        $canonical = function ($value) use (&$canonical) {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            return array_map($canonical, $value);
        };
        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
