<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\SubscriberEmail;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $orders = $request->user()->orders()->orderByDesc('created_at')->get()
            ->map(fn ($order) => app(\App\Services\OrderPresentationService::class)->summary($order))->all();
        return Inertia::render("Profile/Edit", [
            "mustVerifyEmail" => $request->user() instanceof MustVerifyEmail,
            "status" => session("status"),
            "orders" => $orders,
            "location" => Auth::user()->locations,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            $user = $request->user(); $oldEmail = $user->email;
            $user->fill($request->safe()->only(['first_name', 'last_name', 'mobile', 'email']));
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
                \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $oldEmail)->delete();
            }
            $user->save();
            if ($request->validated('street')) {
                $address = $request->safe()->only(['street', 'city', 'state', 'postcode']);
                $location = $user->locations()->first();
                if ($location) $location->update($address);
                else $user->locations()->create($address);
            }
        });

        return Redirect::route("profile.edit");
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            "password" => ["required", "current_password"],
        ]);

        $user = $request->user();

        Auth::logout();

        \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->locations()->detach();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to("/");
    }

    /**
     * Load the admin dashboard.
     *
     * @return void
     */
    public function admin()
    {
        return Inertia::render("Auth/AdminDashboard", [
            "orders" => app(\App\Services\OrderPresentationService::class)->adminOrders(),
            "products" => app(\App\Services\CatalogueService::class)->adminProducts(),
        ]);
    }
}
