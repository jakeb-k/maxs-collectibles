<?php

namespace Tests\Feature;

use App\Models\{NotificationDelivery, Order, User};
use Illuminate\Support\Facades\{Artisan, DB, Hash, Password, URL};
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); Artisan::call('migrate:fresh', ['--force' => true]); $this->freezeTime();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.(crc32($this->name()) % 250 + 1)]);
    }

    private function registration(array $overrides = []): array
    {
        return array_replace(['first_name' => ' Fixture ', 'last_name' => ' Buyer ', 'email' => ' NEW@EXAMPLE.TEST ',
            'password' => 'fixture-password', 'password_confirmation' => 'fixture-password'], $overrides);
    }

    private function profile(User $user, array $overrides = []): array
    {
        return array_replace(['first_name' => 'Fixture', 'last_name' => 'Updated', 'email' => $user->email, 'mobile' => null], $overrides);
    }

    public function test_register_without_mobile_normalizes_identity_and_does_not_accept_privilege_fields(): void
    {
        $this->post(route('register'), $this->registration(['is_admin' => true, 'roles' => ['admin']]))->assertRedirect(route('dashboard', absolute: false));
        $user = User::first(); $this->assertAuthenticatedAs($user); $this->assertNull($user->mobile);
        $this->assertSame('new@example.test', $user->email); $this->assertSame('Fixture', $user->first_name);
        $this->assertFalse((bool) $user->is_admin); $this->assertFalse($user->hasRole('admin')); $this->assertTrue(Hash::check('fixture-password', $user->password));
        $this->get(route('dashboard'))->assertRedirect(route('index')); // Retained verification routes add no new account gate.
    }

    public function test_mobile_is_optional_but_valid_au_strings_normalize_consistently_and_duplicates_fail(): void
    {
        $this->postJson(route('register'), $this->registration(['mobile' => true]))->assertUnprocessable()->assertJsonValidationErrors('mobile');
        $this->postJson(route('register'), $this->registration(['mobile' => '123']))->assertUnprocessable();
        $this->post(route('register'), $this->registration(['mobile' => '+61 400 000 000']))->assertRedirect();
        $user = User::first(); $this->assertSame('0400000000', $user->mobile);
        $this->patch(route('profile.update'), $this->profile($user, ['mobile' => '+61 (400) 000-000']))->assertSessionHasNoErrors();
        $this->assertSame('0400000000', $user->fresh()->mobile);
        $this->post(route('logout')); $this->postJson(route('register'), $this->registration(['email' => 'another@example.test', 'mobile' => '0400000000']))
            ->assertUnprocessable()->assertJsonValidationErrors('mobile');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_rejects_bad_password_rate_limits_and_logout_invalidates_session(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.test', 'mobile' => null]);
        for ($i = 0; $i < 5; $i++) $this->postJson(route('login'), ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson(route('login'), ['email' => $user->email, 'password' => 'password'])->assertUnprocessable(); $this->assertGuest();
        $this->travel(61)->seconds();
        $this->withSession(['fixture' => 'private'])->post(route('login'), ['email' => ' BUYER@EXAMPLE.TEST ', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($user); $this->post(route('logout'))->assertRedirect('/'); $this->assertGuest();
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    }

    public function test_reset_uses_durable_encrypted_intent_invalid_tokens_fail_and_valid_token_is_single_use(): void
    {
        $user = User::factory()->create(['mobile' => null]);
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
        $delivery = NotificationDelivery::where('type', 'password_reset')->firstOrFail(); $token = $delivery->payload['reset_token'];
        $this->assertTrue(Password::broker()->tokenExists($user, $token)); $this->assertSame('pending', $delivery->state);
        $raw = DB::table('notification_deliveries')->where('id', $delivery->id)->value('payload'); $this->assertStringNotContainsString($token, $raw);
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk();
        $data = ['email' => $user->email, 'token' => 'wrong', 'password' => 'changed-password', 'password_confirmation' => 'changed-password'];
        $this->postJson(route('password.store'), $data)->assertUnprocessable();
        $data['token'] = $token; $this->post(route('password.store'), $data)->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('changed-password', $user->fresh()->password));
        $this->postJson(route('password.store'), $data)->assertUnprocessable();
    }

    public function test_reset_expiry_and_password_update_confirmation_and_current_password_are_enforced(): void
    {
        $user = User::factory()->create(['mobile' => null]); $token = Password::createToken($user); $this->travel(61)->minutes();
        $this->postJson(route('password.store'), ['email' => $user->email, 'token' => $token, 'password' => 'changed-password', 'password_confirmation' => 'changed-password'])->assertUnprocessable();
        $this->actingAs($user)->putJson(route('password.update'), ['current_password' => 'wrong', 'password' => 'changed-password', 'password_confirmation' => 'changed-password'])->assertUnprocessable();
        $this->putJson(route('password.update'), ['current_password' => 'password', 'password' => 'changed-password', 'password_confirmation' => 'different'])->assertUnprocessable();
        $this->put(route('password.update'), ['current_password' => 'password', 'password' => 'changed-password', 'password_confirmation' => 'changed-password'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('changed-password', $user->fresh()->password));
        $this->postJson(route('password.confirm'), ['password' => 'wrong'])->assertUnprocessable();
        $this->post(route('password.confirm'), ['password' => 'changed-password'])->assertRedirect(); $this->assertNotNull(session('auth.password_confirmed_at'));
    }

    public function test_profile_allows_null_mobile_and_valid_complete_address_without_mutating_order_snapshots(): void
    {
        $user = User::factory()->create(['mobile' => null]); $order = Order::factory()->create(['user_id' => $user->id]); $before = $order->fresh()->delivery_snapshot;
        $this->actingAs($user)->patch(route('profile.update'), $this->profile($user, ['street' => '2 New Street', 'city' => 'Darwin', 'state' => ' nt ', 'postcode' => '0800', 'is_admin' => true]))->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->mobile); $this->assertFalse((bool) $user->fresh()->is_admin);
        $this->assertSame('0800', $user->locations()->first()->postcode); $this->assertSame($before, $order->fresh()->delivery_snapshot);
        $this->patch(route('profile.update'), $this->profile($user))->assertSessionHasNoErrors(); $this->assertDatabaseCount('locations', 1);
    }

    public function test_invalid_partial_address_or_duplicate_email_rolls_back_all_profile_changes(): void
    {
        $user = User::factory()->create(['mobile' => null]); $other = User::factory()->create(['mobile' => null]);
        $this->actingAs($user)->patchJson(route('profile.update'), $this->profile($user, ['street' => 'Incomplete']))->assertUnprocessable();
        $this->assertNotSame('Updated', $user->fresh()->last_name); $this->assertDatabaseCount('locations', 0);
        $this->patchJson(route('profile.update'), $this->profile($user, ['email' => $other->email]))->assertUnprocessable();
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_email_change_resets_verification_and_revokes_old_password_reset_token(): void
    {
        $user = User::factory()->create(['mobile' => null]); Password::createToken($user);
        $this->actingAs($user)->patch(route('profile.update'), $this->profile($user, ['email' => ' UPDATED@EXAMPLE.TEST ']))->assertSessionHasNoErrors();
        $this->assertSame('updated@example.test', $user->fresh()->email); $this->assertNull($user->fresh()->email_verified_at);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_delete_requires_password_retains_nullable_order_owner_and_removes_reset_credentials(): void
    {
        $user = User::factory()->create(['mobile' => null]); $order = Order::factory()->create(['user_id' => $user->id]); Password::createToken($user);
        $this->actingAs($user)->deleteJson(route('profile.destroy'), ['password' => 'wrong'])->assertUnprocessable(); $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/'); $this->assertGuest();
        $this->assertNull($order->fresh()->user_id); $this->assertSame('buyer@example.test', $order->fresh()->contact_snapshot['email']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_retained_verification_routes_require_auth_correct_signed_identity_and_allow_resend(): void
    {
        $user = User::factory()->unverified()->create(['mobile' => null]); $this->get(route('verification.notice'))->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
        $this->post(route('verification.send'))->assertSessionHas('status', 'verification-link-sent');
        $delivery = NotificationDelivery::where('type', 'verification')->firstOrFail();
        preg_match('/href="([^"]+)"/', $delivery->payload['html'], $match); $link = html_entity_decode($match[1]);
        $bad = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('wrong')]);
        $this->get($bad)->assertForbidden(); $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->get($link)->assertRedirect(); $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get($link)->assertRedirect();
    }

    public function test_admin_gate_uses_role_and_cannot_be_claimed_by_legacy_boolean_or_another_account(): void
    {
        $this->getJson(route('admin.dashboard'))->assertUnauthorized();
        $user = User::factory()->create(['mobile' => null, 'is_admin' => true]);
        $this->actingAs($user)->getJson(route('admin.dashboard'))->assertForbidden();
        $user->assignRole(Role::findOrCreate('admin')); $this->get(route('admin.dashboard'))->assertOk();
    }
}
