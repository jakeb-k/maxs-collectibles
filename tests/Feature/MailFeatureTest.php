<?php

namespace Tests\Feature;

use App\Models\SubscriberEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MailFeatureTest extends TestCase
{
    use RefreshDatabase;

    public User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->user->assignRole(Role::create(["name" => "admin"]));
        $this->actingAs($this->user);
    }

    #[Test]
    public function it_sends_a_contact_email_to_the_admin()
    {
        $data =  [
            "first_name" => "John",
            "last_name" => "Doe",
            "email" => "john.doe@example.com",
            "message" => "This is a test message.",
        ];
        // Act: Send a contact email
        $response = $this->postJson(route("contact.email"),$data);

        // Verify the durable intent; the scheduler owns provider delivery.
        $response->assertStatus(200)->assertJson(["success" => true]);

        $this->assertDatabaseHas('notification_deliveries', ['type' => 'contact', 'state' => 'pending', 'recipient' => config('mail.contact_to')]);
    }

    #[Test]
    public function it_validates_contact_email_request()
    {
        // Act: Send an invalid contact email request
        $response = $this->postJson(route("contact.email"), [
            "first_name" => "",
            "email" => "invalid-email",
            "message" => "",
        ]);

        // Assert: Verify validation errors
        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(["first_name", "email", "message"]);
    }

    #[Test]
    public function it_subscribes_an_email_to_the_mailing_list()
    {

        // Act
        $response = $this->postJson(route("subscribe.email"), [
            "email" => "subscriber@example.com",
        ]);

        // Assert
        $response
            ->assertStatus(200)
            ->assertJson(["success" => true, "delivery_state" => "pending"]);

        $this->assertDatabaseHas("subscriber_emails", [
            "email" => "subscriber@example.com",
        ]);

        $this->assertDatabaseHas('notification_deliveries', ['type' => 'welcome', 'state' => 'pending', 'recipient' => 'subscriber@example.com']);
    }


    #[Test]
    public function it_validates_subscription_request()
    {
        // Arrange: Create an existing subscriber
        SubscriberEmail::factory()->create([
            "email" => "subscriber@example.com",
        ]);

        // Act: Try to subscribe with the same email
        $response = $this->postJson(route("subscribe.email"), [
            "email" => "subscriber@example.com",
        ]);

        // Assert: Verify validation error
        $response->assertStatus(422)->assertJsonValidationErrors(["email"]);
    }

    #[Test]
    public function it_unsubscribes_an_email_from_the_mailing_list()
    {
        // Arrange: Create a subscriber
        SubscriberEmail::factory()->create([
            "email" => "unsubscribe@example.com",
        ]);

        // Act: Unsubscribe the email
        $response = $this->get(
            URL::signedRoute("unsubscribe.email", [
                "email" => "unsubscribe@example.com",
            ])
        );

        // Assert: Verify the email was unsubscribed
        $response->assertStatus(200)->assertViewIs("mail.unsub");

        $this->assertDatabaseMissing("subscriber_emails", [
            "email" => "unsubscribe@example.com",
        ]);
    }

    #[Test]
    public function it_handles_unsubscribe_for_nonexistent_email()
    {
        // Act: Unsubscribe an email that doesn't exist
        $response = $this->get(
            URL::signedRoute("unsubscribe.email", [
                "email" => "nonexistent@example.com",
            ])
        );

        // Assert: Verify no error and correct view
        $response->assertStatus(200)->assertViewIs("mail.unsub");
    }
}
