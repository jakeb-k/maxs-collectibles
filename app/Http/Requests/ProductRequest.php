<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('authoring')) return;
        $encoded = $this->input('authoring');
        $invalid = fn () => \Illuminate\Validation\ValidationException::withMessages(['authoring' => 'Use a valid authoring JSON object with supported non-file fields.']);
        if (!is_string($encoded) || strlen($encoded) > 1048576) throw $invalid();
        try { $object = json_decode($encoded, false, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw $invalid(); }
        if (!$object instanceof \stdClass) throw $invalid();
        $allowed = ['name','description','category_ids','type_tag_ids','type','url','delivery_date','sku','price_minor','discount_bps','currency','stock_quantity','available','facts','options','no_images','kept_images','image_metadata','image_order'];
        if (array_diff(array_keys(get_object_vars($object)), $allowed)) throw $invalid();
        $data = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:20000'],
            'category_ids' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'category_ids.*' => ['required', 'integer', 'distinct', Rule::exists('tags', 'id')->where('type', 'category')],
            'type_tag_ids' => ['present', 'array', 'list', 'max:20'],
            'type_tag_ids.*' => ['required', 'integer', 'distinct', Rule::exists('tags', 'id')->where(fn ($q) => $q->where('type', '!=', 'category')->orWhereNull('type'))],
            'type' => ['nullable', 'string', 'max:100'],
            'url' => ['nullable', 'url:http,https', 'max:2000'],
            'delivery_date' => ['nullable', 'integer', 'min:0', 'max:365'],
            'sku' => ['nullable', 'string', 'max:255'],
            'price_minor' => ['required', 'integer', 'min:0', 'max:999999999'],
            'discount_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'currency' => ['required', Rule::in(['AUD'])],
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'available' => ['required', 'boolean'],
            'facts' => ['present', 'array', 'list', 'max:100'],
            'facts.*' => ['required', 'array:label,value'],
            'facts.*.label' => ['required', 'string', 'max:100'],
            'facts.*.value' => ['required', 'string', 'max:2000'],
            'options' => ['present', 'array', 'list', 'max:20'],
            'options.*' => ['required', 'array:type,values'],
            'options.*.type' => ['required', 'string', 'distinct', 'max:100'],
            'options.*.values' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'options.*.values.*' => ['required', 'string', 'max:200'],
            'no_images' => ['required', 'boolean'],
            // Explicit keep list prevents an omitted field silently deleting current images.
            'kept_images' => ['present', 'array', 'list', 'max:20'],
            'kept_images.*' => ['required', 'array:id,alt_text,caption'],
            'kept_images.*.id' => ['required', 'integer', 'distinct'],
            'kept_images.*.alt_text' => ['nullable', 'string', 'max:255'],
            'kept_images.*.caption' => ['nullable', 'string', 'max:2000'],
            'images' => ['sometimes', 'array', 'list', 'max:20'],
            'images.*' => ['bail', 'required', 'file', 'max:20480', 'mimetypes:image/jpeg,image/png,image/webp', 'dimensions:min_width=1,min_height=1,max_width=12000,max_height=12000'],
            'image_metadata' => ['sometimes', 'array', 'list', 'max:20'],
            'image_metadata.*' => ['array:alt_text,caption'],
            'image_metadata.*.alt_text' => ['nullable', 'string', 'max:255'],
            'image_metadata.*.caption' => ['nullable', 'string', 'max:2000'],
            'image_order' => ['sometimes', 'array', 'list', 'max:40'],
            'image_order.*' => ['required', 'string', 'distinct', 'regex:/^(existing|new):[0-9]+$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) return;
            $data = $validator->getData();
            foreach ($data['options'] as $index => $option) {
                if (count(array_unique($option['values'])) !== count($option['values'])) {
                    $validator->errors()->add("options.$index.values", 'Option values must be distinct.');
                }
            }
            $count = count($data['kept_images']) + count($this->file('images', []));
            if ((bool) $data['no_images'] !== ($count === 0)) {
                $validator->errors()->add('no_images', 'Choose photos or explicitly confirm that this item has no photos.');
            }
            $expected = array_merge(array_map(fn ($image) => 'existing:'.$image['id'], $data['kept_images']), array_map(fn ($i) => 'new:'.$i, array_keys($this->file('images', []))));
            if (isset($data['image_order'])) {
                $actual = $data['image_order']; sort($expected); sort($actual);
                if ($actual !== $expected) $validator->errors()->add('image_order', 'Image order must name every kept and new image exactly once.');
            }
            if (count($data['image_metadata'] ?? []) > count($this->file('images', []))) {
                $validator->errors()->add('image_metadata', 'Metadata must correspond to uploaded images.');
            }
        }];
    }
}
