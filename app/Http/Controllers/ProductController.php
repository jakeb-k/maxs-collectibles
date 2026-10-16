<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Services\CatalogueService;
use App\Services\ProductAuthoringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Tags\Tag;

class ProductController extends Controller
{
    public function __construct(private CatalogueService $catalogue, private ProductAuthoringService $authoring) {}

    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['sometimes', 'string', 'max:100'], 'type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['newest', 'name', 'price_asc', 'price_desc', 'popular'])],
            'min_price_minor' => ['sometimes', 'integer', 'min:0', 'max:999999999'],
            'max_price_minor' => ['sometimes', 'integer', 'min:0', 'max:999999999', Rule::when($request->has('min_price_minor'), ['gte:min_price_minor'])],
            'availability' => ['sometimes', Rule::in(['all', 'in_stock'])], 'discounted' => ['sometimes', 'boolean'],
        ]);
    }

    public function index(Request $request, string $category)
    {
        $filters = $this->filters($request);
        $query = $this->catalogue->query();
        if ($category === 'favourites') {
            $products = [];
        } else {
            if ($category === 'sale') $filters['discounted'] = true;
            elseif ($category === 'popular') {
                $query->whereHas('orders', fn ($q) => $q->whereIn('status', ['Paid', 'Completed']));
                $filters['sort'] ??= 'popular';
            } elseif ($request->boolean('tag')) {
                $query->whereHas('tags', fn ($q) => $q->where('type', $category));
            } else $filters['category'] = $category;
            $products = $this->catalogue->filter($query, $filters)->limit(200)->get()->map(fn ($product) => $this->catalogue->dto($product))->all();
        }
        return Inertia::render('Products/ProductIndexLayout', ['products' => $products, 'category' => $category,
            'type' => $filters['type'] ?? null, 'filters' => $filters]);
    }

    public function resolve(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'integer', 'min:1', 'max:999999999']]);
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $products = $this->catalogue->query()->whereIn('products.id', $ids)->get()->map(fn ($product) => $this->catalogue->dto($product))->all();
        return response()->json(['products' => $products, 'missing' => array_values(array_diff($ids, array_column($products, 'id')))]);
    }

    public function show(Product $product)
    {
        $related = $this->catalogue->query()->where('products.id', '!=', $product->id)
            ->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $product->tags()->pluck('tags.id')))->limit(6)->get();
        return Inertia::render('Products/ProductShowLayout', ['product' => $this->catalogue->dto($product),
            'relatedItems' => $related->map(fn ($item) => $this->catalogue->dto($item))->all()]);
    }

    public function store(ProductRequest $request)
    {
        $product = $this->authoring->save(null, $request->validated(), $request->file('images', []));
        return response()->json(['success' => 'Item created.', 'product' => $this->catalogue->dto($product, true), 'products' => $this->catalogue->adminProducts()], 201);
    }

    public function update(ProductRequest $request, Product $product)
    {
        $product = $this->authoring->save($product, $request->validated(), $request->file('images', []));
        return response()->json(['success' => 'Item updated.', 'product' => $this->catalogue->dto($product, true), 'products' => $this->catalogue->adminProducts()]);
    }

    public function getBestsellers()
    {
        $items = $this->catalogue->query()->whereHas('orders', fn ($q) => $q->whereIn('status', ['Paid', 'Completed']))
            ->orderByDesc('paid_quantity')->orderBy('id')->limit(10)->get();
        return response()->json(['bestsellers' => $items->map(fn ($item) => $this->catalogue->dto($item))->all()]);
    }

    public function search(Request $request, string $query)
    {
        validator(['q' => $query], ['q' => ['required', 'string', 'max:100']])->validate();
        $filters = $this->filters($request);
        $filters['q'] = $query;
        return response()->json(['products' => $this->catalogue->filter($this->catalogue->query(), $filters)->limit(100)->get()->map(fn ($item) => $this->catalogue->dto($item))->all(), 'query' => $query]);
    }

    public function setAvailable(Request $request, Product $product)
    {
        $data = $request->validate(['available' => ['sometimes', 'required', 'boolean']]);
        $this->authoring->setAvailable($product, array_key_exists('available', $data) ? (bool) $data['available'] : null);
        return response()->json(['products' => $this->catalogue->adminProducts()]);
    }

    public function destroy(Product $product)
    {
        $this->authoring->delete($product);
        return response()->json(['success' => 'Item deleted.', 'products' => $this->catalogue->adminProducts()]);
    }

    public function getTags()
    {
        $tags = Tag::orderBy('id')->get()->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name, 'slug' => $tag->slug, 'type' => $tag->type]);
        return response()->json(['categories' => $tags->where('type', 'category')->values(), 'types' => $tags->where('type', '!=', 'category')->values()]);
    }
}
