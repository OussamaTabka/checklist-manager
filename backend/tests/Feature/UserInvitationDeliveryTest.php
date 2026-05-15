<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserInvitationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_creation_returns_setup_link_when_mail_delivery_falls_back_to_log(): void
    {
        config([
            'app.env' => 'local',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.brevo.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'your-brevo-smtp-login@example.com',
            'mail.mailers.smtp.password' => 'your-brevo-smtp-key',
            'mail.from.address' => 'your-verified-sender@example.com',
        ]);

        $this->ensureRoles();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/users', [
            'name' => 'New Tester',
            'email' => 'new.tester@example.com',
            'role' => 'testeur',
        ]);

        $response->assertCreated()
            ->assertJsonPath('delivery_method', 'log')
            ->assertJsonPath('user.email', 'new.tester@example.com');

        $this->assertIsString($response->json('setup_link'));
        $this->assertStringContainsString('/accept-invitation?selector=', $response->json('setup_link'));
    }

    private function ensureRoles(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('chef', 'web');
        Role::findOrCreate('testeur', 'web');
    }
}
