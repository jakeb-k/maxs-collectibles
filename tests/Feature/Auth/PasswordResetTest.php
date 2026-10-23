<?php

namespace Tests\Feature\Auth;

use App\Models\{NotificationDelivery, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    private function requestReset(User $user): NotificationDelivery
    {
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
        $intent = NotificationDelivery::where('type', 'password_reset')->where('recipient', $user->email)->sole();
        $this->assertSame('pending', $intent->state);
        $this->assertNotEmpty($intent->payload['reset_token']);
        return $intent;
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        $this->requestReset(User::factory()->create());
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();
        $intent = $this->requestReset($user);
        $this->get(route('password.reset', ['token' => $intent->payload['reset_token'], 'email' => $user->email]))->assertOk();
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        $user = User::factory()->create();
        $intent = $this->requestReset($user);
        $this->post('/reset-password', ['token' => $intent->payload['reset_token'], 'email' => $user->email,
            'password' => 'new-fixture-password', 'password_confirmation' => 'new-fixture-password'])
            ->assertSessionHasNoErrors()->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('new-fixture-password', $user->fresh()->password));
    }
}
