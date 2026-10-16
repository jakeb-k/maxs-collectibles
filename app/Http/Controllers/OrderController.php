<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Policies\OrderPolicy;
use App\Services\{OrderAccessService, OrderLifecycleService, OrderPresentationService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class OrderController extends Controller
{
    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless(app(OrderPolicy::class)->view($request->user(), $order, app(OrderAccessService::class)->guestAuthorized($request, $order)), 403);
    }

    public function show(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);
        // Exchange a bearer URL for an exact-order server session grant before rendering assets/history.
        if ($request->query->count()) return to_route('orders.show', $order);
        // A fresh document drops scripts/assets inherited from another SPA screen.
        if ($request->header('X-Inertia')) return Inertia::location(route('orders.show', $order));
        return Inertia::render('Products/OrderShowLayout', ['order' => app(OrderPresentationService::class)->detail($order)]);
    }

    public function image(Request $request, Order $order, int $line)
    {
        $this->authorizeOrder($request, $order);
        $snapshot = $order->lines()->withTrashed()->whereKey($line)->firstOrFail();
        abort_unless($snapshot->image_path && $snapshot->image_disk, 404);
        $disk = Storage::disk($snapshot->image_disk);
        abort_unless($disk->exists($snapshot->image_path), 404);
        $mime = $disk->mimeType($snapshot->image_path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        return $disk->response($snapshot->image_path, null, ['Content-Type' => $mime], 'inline');
    }

    public function completeOrder(Request $request, Order $order)
    {
        abort_unless(app(OrderPolicy::class)->complete($request->user(), $order), 403);
        app(OrderLifecycleService::class)->complete($order);
        return response()->json(['message' => 'Order completed successfully', 'orders' => app(OrderPresentationService::class)->adminOrders()]);
    }
}
