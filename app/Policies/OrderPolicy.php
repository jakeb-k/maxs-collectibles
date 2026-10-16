<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(?User $user, Order $order, bool $guestAuthorized = false): bool
    {
        return ($user && ($user->hasRole('admin') || ($order->user_id !== null && $user->id === $order->user_id))) || $guestAuthorized;
    }

    public function complete(User $user, Order $order): bool { return $user->hasRole('admin'); }
}
