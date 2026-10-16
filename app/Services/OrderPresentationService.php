<?php

namespace App\Services;

use App\Models\Order;

/** Explicit purchase DTOs never consult mutable products, user addresses or provider sessions. */
class OrderPresentationService
{
    public function summary(Order $order): array
    {
        return ['id' => $order->id, 'code' => $order->code, 'status' => $order->status, 'currency' => $order->currency,
            'subtotal_minor' => $order->subtotal_minor, 'discount_minor' => $order->discount_minor, 'tax_minor' => $order->tax_minor,
            'delivery_minor' => $order->delivery_minor, 'total_minor' => $order->total_minor, 'rule_version' => $order->rule_version,
            'paid_at' => $order->paid_at?->toIso8601String(), 'completed_at' => $order->completed_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String()];
    }

    public function detail(Order $order): array
    {
        $attempt = $order->checkoutAttempt;
        $lines = $order->lines()->withTrashed()->orderBy('id')->get()->map(fn ($line) => [
            'id' => $line->id, 'product_id' => $line->product_id, 'title' => $line->title, 'description' => $line->description,
            'facts' => $line->facts, 'selected_options' => $line->selected_options, 'quantity' => $line->quantity,
            'original_unit_minor' => $line->original_unit_minor, 'net_unit_minor' => $line->net_unit_minor,
            'tax_unit_minor' => $line->tax_unit_minor, 'payable_unit_minor' => $line->payable_unit_minor,
            'line_payable_minor' => $line->payable_unit_minor * $line->quantity,
            'image_alt' => $line->image_alt, 'image_url' => $line->image_path ? route('orders.image', ['order' => $order->id, 'line' => $line->id], false) : null,
        ])->all();
        return $this->summary($order) + ['contact' => $order->contact_snapshot, 'delivery' => $order->delivery_snapshot, 'lines' => $lines,
            'attempt_key' => $attempt?->key, 'checkout_state' => $attempt?->state, 'needs_review' => (bool) ($attempt?->reconciliation_evidence['quarantined'] ?? false)];
    }

    public function adminOrders(): array
    {
        return Order::with('checkoutAttempt')->orderByDesc('created_at')->get()->map(fn ($order) => $this->detail($order))->all();
    }
}
