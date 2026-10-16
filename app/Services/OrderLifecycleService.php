<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class OrderLifecycleService
{
    public function complete(Order $order): Order
    {
        $work = function ($products, $attempt, $locked) {
            if ($attempt && ($attempt->reconciliation_evidence['quarantined'] ?? false)) throw new HttpResponseException(response()->json(['code' => 'order_needs_review'], 409));
            if ($locked->status === 'Completed') return $locked;
            if ($locked->status !== 'Paid') throw new HttpResponseException(response()->json(['code' => 'order_not_paid', 'message' => 'Only paid orders can be completed.'], 409));
            $locked->update(['status' => 'Completed', 'completed_at' => now()]);
            if ($attempt) $attempt->update(['state' => 'completed']);
            app(NotificationIntentService::class)->forOrderLocked($locked, 'completion');
            return $locked;
        };
        if ($attempt = $order->checkoutAttempt) return app(StockAllocationService::class)->withLockedAttempt($attempt->id, $work);
        return DB::transaction(fn () => $work(collect(), null, Order::whereKey($order->id)->lockForUpdate()->firstOrFail()));
    }
}
