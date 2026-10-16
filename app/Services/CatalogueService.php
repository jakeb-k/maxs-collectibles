<?php

namespace App\Services;

use App\Models\Image;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CatalogueService
{
    public function query(): Builder
    {
        return Product::query()->select('products.*')->with(['options', 'images', 'tags'])
            ->selectSub(DB::table('order_products')->join('orders', 'orders.id', '=', 'order_products.order_id')
                ->whereColumn('order_products.product_id', 'products.id')->whereIn('orders.status', ['Paid', 'Completed'])
                ->selectRaw('COALESCE(SUM(order_products.quantity), 0)'), 'paid_quantity')
            ->withSum(['allocations as held_quantity' => fn ($q) => $q->where('state', 'held')], 'quantity');
    }

    public function filter(Builder $query, array $filters): Builder
    {
        if (isset($filters['q']) && $filters['q'] !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(fn ($q) => $q->where('products.name', 'like', $term)->orWhere('products.type', 'like', $term)
                ->orWhereHas('tags', fn ($tags) => $tags->where('name->en', 'like', $term)));
        }
        foreach (['category', 'type'] as $key) {
            if (!empty($filters[$key]) && $filters[$key] !== 'all') {
                $value = $filters[$key];
                $query->whereHas('tags', fn ($q) => $q->where(fn ($tags) => $tags->where('name->en', $value)->orWhere('slug->en', Str::slug($value))));
            }
        }

        if (!empty($filters['discounted'])) $query->where('discount_bps', '>', 0);
        if (($filters['availability'] ?? 'all') === 'in_stock') {
            $query->where('available', true)->whereRaw('stock_quantity > (SELECT COALESCE(SUM(quantity), 0) FROM stock_allocations WHERE stock_allocations.product_id = products.id AND state = ?)', ['held']);
        }
        $sort = $filters['sort'] ?? 'newest';
        if (isset($filters['min_price_minor']) || isset($filters['max_price_minor']) || in_array($sort, ['price_asc', 'price_desc'], true)) {
            $pricing = app(PricingService::class);
            $priced = (clone $query)->get()->map(fn ($product) => ['id' => $product->id, 'amount' => $pricing->display($product)['display_unit_minor']]);
            $priced = $priced->filter(fn ($item) => (!isset($filters['min_price_minor']) || ($item['amount'] !== null && $item['amount'] >= $filters['min_price_minor']))
                && (!isset($filters['max_price_minor']) || ($item['amount'] !== null && $item['amount'] <= $filters['max_price_minor'])));
            $query->whereIn('products.id', $priced->pluck('id'));
            if (in_array($sort, ['price_asc', 'price_desc'], true) && $priced->isNotEmpty()) {
                $ids = $priced->sort(function ($a, $b) use ($sort) {
                    if ($a['amount'] === null) return $b['amount'] === null ? $a['id'] <=> $b['id'] : 1;
                    if ($b['amount'] === null) return -1;
                    $comparison = $a['amount'] <=> $b['amount'];
                    return ($sort === 'price_desc' ? -$comparison : $comparison) ?: $a['id'] <=> $b['id'];
                })->pluck('id')->map(fn ($id) => (int) $id)->all();
                return $query->orderByRaw('FIELD(products.id,'.implode(',', $ids).')');
            }
        }
        return match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('price_minor')->orderBy('id'),
            'price_desc' => $query->orderByDesc('price_minor')->orderBy('id'),
            'name' => $query->orderBy('name')->orderBy('id'),
            'popular' => $query->orderByDesc('paid_quantity')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    public function dto(Product $product, bool $admin = false): array
    {
        $product->loadMissing(['options', 'images', 'tags']);
        $held = $product->getAttribute('held_quantity');
        if ($held === null) $held = $product->allocations()->where('state', 'held')->sum('quantity');
        $remaining = max(0, $product->stock_quantity - (int) $held);
        $images = $product->images->sortBy('position')->values()->map(fn (Image $image) => $this->imageDto($image))->all();
        $dto = [
            'id' => $product->id, 'name' => $product->name, 'description' => $product->description,
            'price_minor' => $product->price_minor, 'discount_bps' => $product->discount_bps, 'currency' => $product->currency,
            'facts' => $product->facts ?? [], 'available' => $product->available, 'available_quantity' => $remaining,
            'purchasable' => $product->available && $remaining > 0 && !$product->trashed(),
            'no_images' => count(array_filter($images, fn ($image) => $image['url'] !== null)) === 0,
            'images' => $images,
            'options' => $product->options->map(fn ($option) => ['type' => $option->type, 'values' => $option->values])->values()->all(),
            'tags' => $product->tags->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name, 'slug' => $tag->slug, 'type' => $tag->type])->values()->all(),
            'paid_quantity' => (int) ($product->getAttribute('paid_quantity') ?? 0),
        ];
        if ($admin) $dto += ['stock_quantity' => $product->stock_quantity, 'held_quantity' => (int) $held,
            'type' => $product->type, 'url' => $product->url, 'delivery_date' => $product->delivery_date, 'sku' => $product->sku,
            'category_ids' => $product->tags->where('type', 'category')->pluck('id')->values()->all(),
            'type_tag_ids' => $product->tags->where('type', '!=', 'category')->pluck('id')->values()->all()];
        return $dto + app(PricingService::class)->display($product);
    }

    public function imageDto(Image $image): array
    {
        $disk = $image->disk ?: config('commerce.public_disk');
        $safe = !str_contains($image->file_path, '..') && !str_starts_with($image->file_path, '/') && !str_contains($image->file_path, '://');
        $url = $safe && Storage::disk($disk)->exists($image->file_path) ? Storage::disk($disk)->url($image->file_path) : null;
        $variants = [];
        foreach ($image->derivatives ?? [] as $variant) {
            if (!str_contains($variant['file_path'], '..') && Storage::disk($disk)->exists($variant['file_path'])) {
                $variants[] = ['url' => Storage::disk($disk)->url($variant['file_path']), 'width' => $variant['width'], 'height' => $variant['height']];
            }
        }
        return ['id' => $image->id, 'url' => $url, 'width' => $image->width, 'height' => $image->height,
            'alt_text' => $image->alt_text, 'caption' => $image->caption, 'position' => $image->position, 'derivatives' => $variants];
    }

    public function adminProducts(): array
    {
        return $this->query()->orderByDesc('updated_at')->get()->map(fn ($product) => $this->dto($product, true))->all();
    }
}
