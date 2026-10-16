<?php

namespace App\Services;

use App\Models\Image;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Tags\Tag;

class ProductAuthoringService
{
    public function __construct(private StockAllocationService $stock, private ProductImageService $images) {}

    public function save(?Product $product, array $data, array $files): Product
    {
        $staged = $this->images->stage($files, $data['image_metadata'] ?? []);
        try {
            return DB::transaction(function () use ($product, $data, $staged) {
                if ($product) {
                    $product = $this->stock->lockProducts([$product->id])->get($product->id);
                    abort_if(!$product || $product->trashed(), 404);
                    $this->stock->assertStockFloor($product, (int) $data['stock_quantity']);
                } else {
                    $product = new Product;
                }
                $existing = $product->exists ? $product->images()->get()->keyBy('id') : collect();
                foreach ($data['kept_images'] as $item) {
                    if (!$existing->has($item['id'])) {
                        throw ValidationException::withMessages(['kept_images' => 'A kept photo does not belong to this item. Refresh the form.']);
                    }
                }
                $attributes = array_intersect_key($data, array_flip(['name', 'description', 'type', 'url', 'delivery_date', 'sku', 'price_minor', 'discount_bps', 'currency', 'stock_quantity', 'available', 'facts']));
                $product->fill($attributes)->save();
                $product->syncTags(Tag::findMany(array_merge($data['category_ids'], $data['type_tag_ids'])));
                $product->options()->delete();
                $product->options()->createMany($data['options']);
                $selected = [];
                foreach ($data['kept_images'] as $item) {
                    $image = $existing->get($item['id']);
                    $image->fill(['alt_text' => $item['alt_text'] ?? null, 'caption' => $item['caption'] ?? null])->save();
                    $selected['existing:'.$image->id] = $image;
                }
                foreach ($staged as $index => $attributes) $selected['new:'.$index] = Image::create($attributes);
                $order = $data['image_order'] ?? array_keys($selected);
                $ids = [];
                foreach ($order as $position => $reference) {
                    if (!isset($selected[$reference])) throw ValidationException::withMessages(['image_order' => 'Refresh the photo list.']);
                    $image = $selected[$reference];
                    $image->update(['position' => $position]);
                    $ids[] = $image->id;
                }
                $product->images()->sync($ids);
                $removed = $existing->except($ids);
                DB::afterCommit(function () use ($removed) {
                    foreach ($removed as $image) $this->images->removeUnreferenced($image);
                });
                return $product;
            });
        } catch (\Throwable $error) {
            // A post-commit cleanup error must not remove files belonging to committed rows.
            foreach ($staged as $image) {
                if (!Image::where('disk', $image['disk'])->where('file_path', $image['file_path'])->exists()) {
                    $this->images->cleanStaged([$image]);
                }
            }
            throw $error;
        }
    }

    public function setAvailable(Product $product, ?bool $available): Product
    {
        return DB::transaction(function () use ($product, $available) {
            $locked = $this->stock->lockProducts([$product->id])->get($product->id);
            abort_if(!$locked || $locked->trashed(), 404);
            $locked->update(['available' => $available ?? !$locked->available]);
            return $locked;
        });
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $locked = $this->stock->lockProducts([$product->id])->get($product->id);
            abort_if(!$locked || $locked->trashed(), 404);
            // Existing held commitments and files remain available to purchase snapshots.
            $locked->delete();
        });
    }
}
