<?php

namespace Tests\Feature;

use App\Models\ConsoleAudit;
use App\Models\User;
use App\Models\WebClientOsLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebClientOsLoginTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT_PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://console.example.test']);
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443]);
    }

    public function test_authentication_is_required(): void
    {
        $this->getJson('/webclient/os-login?peerId=123456')->assertRedirect('/login');
        $this->postJson('/webclient/os-login/reveal', [
            'peerId' => '123456',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertRedirect('/login');
        $this->putJson('/webclient/os-login', [
            'peerId' => '123456',
            'password' => 'remote-os-secret',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertRedirect('/login');
        $this->deleteJson('/webclient/os-login', [
            'peerId' => '123456',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertRedirect('/login');
    }

    public function test_metadata_never_returns_the_password_and_storage_is_encrypted_and_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner)->putJson('/webclient/os-login', [
            'peerId' => '123456',
            'password' => 'remote-os-secret',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertNoContent()->assertDontSee('remote-os-secret');

        $raw = (string) DB::table('webclient_os_logins')->value('password');
        $this->assertNotSame('remote-os-secret', $raw);
        $this->assertStringNotContainsString('remote-os-secret', $raw);

        $stored = WebClientOsLogin::query()->sole();
        $this->assertSame('remote-os-secret', $stored->password);
        $this->assertArrayNotHasKey('password', $stored->toArray());

        $this->actingAs($owner)
            ->getJson('/webclient/os-login?peerId=123456')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['enabled' => true])
            ->assertDontSee('remote-os-secret');

        $this->actingAs($other)
            ->getJson('/webclient/os-login?peerId=123456')
            ->assertOk()
            ->assertExactJson(['enabled' => false]);
    }

    public function test_reveal_requires_current_password_and_is_no_store(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/webclient/os-login', [
            'peerId' => 'abc-123',
            'password' => 'remote-os-secret',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertNoContent();

        $this->postJson('/webclient/os-login/reveal', [
            'peerId' => 'abc-123',
            'currentPassword' => 'wrong-password',
        ])->assertForbidden()->assertDontSee('remote-os-secret');

        $this->postJson('/webclient/os-login/reveal', [
            'peerId' => 'abc-123',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['password' => 'remote-os-secret']);
    }

    public function test_sso_provisioned_accounts_cannot_reveal_or_mutate_os_passwords(): void
    {
        $user = User::factory()->create(['auth_provider' => 'oidc']);
        WebClientOsLogin::query()->create([
            'user_id' => $user->id,
            'peer_id' => 'abc-123',
            'password' => 'remote-os-secret',
        ]);
        $this->actingAs($user);

        $this->postJson('/webclient/os-login/reveal', [
            'peerId' => 'abc-123',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertForbidden()->assertDontSee('remote-os-secret');
        $this->putJson('/webclient/os-login', [
            'peerId' => 'abc-123',
            'password' => 'replacement-secret',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertForbidden();
        $this->deleteJson('/webclient/os-login', [
            'peerId' => 'abc-123',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertForbidden();

        $this->assertSame('remote-os-secret', WebClientOsLogin::query()->sole()->password);
    }

    public function test_save_reveal_and_delete_are_audited_without_secret_material(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->putJson('/webclient/os-login', [
            'peerId' => 'abc-123',
            'password' => 'remote-os-secret',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertNoContent();
        $this->postJson('/webclient/os-login/reveal', [
            'peerId' => 'abc-123',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertOk();
        $this->deleteJson('/webclient/os-login', [
            'peerId' => 'abc-123',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertNoContent();

        $this->assertSame([
            'webclient.os-login.save',
            'webclient.os-login.reveal',
            'webclient.os-login.delete',
        ], ConsoleAudit::query()->orderBy('id')->pluck('action')->all());
        $auditText = ConsoleAudit::query()->pluck('summary')->implode(' ');
        $this->assertStringContainsString('abc-123', $auditText);
        $this->assertStringNotContainsString('remote-os-secret', $auditText);
        $this->assertStringNotContainsString(self::ACCOUNT_PASSWORD, $auditText);
    }

    public function test_audit_failure_rolls_back_save_and_delete(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reject_console_audit
            BEFORE INSERT ON console_audits
            BEGIN
                SELECT RAISE(FAIL, 'audit blocked');
            END
            SQL);

        $this->putJson('/webclient/os-login', [
            'peerId' => 'peer-audit-failure',
            'password' => 'remote-secret',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertStatus(500);

        $this->assertDatabaseMissing('webclient_os_logins', [
            'user_id' => $user->id,
            'peer_id' => 'peer-audit-failure',
        ]);

        WebClientOsLogin::query()->create([
            'user_id' => $user->id,
            'peer_id' => 'peer-audit-failure',
            'password' => 'remote-secret',
        ]);

        $this->deleteJson('/webclient/os-login', [
            'peerId' => 'peer-audit-failure',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertStatus(500);

        $this->assertDatabaseHas('webclient_os_logins', [
            'user_id' => $user->id,
            'peer_id' => 'peer-audit-failure',
        ]);
    }

    public function test_failed_reauthentication_does_not_create_success_audit_or_change_storage(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/webclient/os-login', [
            'peerId' => 'abc-123',
            'password' => 'remote-os-secret',
            'currentPassword' => 'wrong-password',
        ])->assertForbidden();

        $this->assertDatabaseCount('webclient_os_logins', 0);
        $this->assertDatabaseCount('console_audits', 0);
    }

    public function test_sensitive_routes_are_rate_limited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->getJson('/webclient/os-login?peerId=rate-limit-peer')->assertOk();
        }
        $this->getJson('/webclient/os-login?peerId=rate-limit-peer')->assertTooManyRequests();
    }

    public function test_update_and_delete_are_idempotent_without_echoing_secrets(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (['first-secret', 'replacement-secret'] as $password) {
            $this->putJson('/webclient/os-login', [
                'peerId' => 'abc-123',
                'password' => $password,
                'currentPassword' => self::ACCOUNT_PASSWORD,
            ])->assertNoContent()->assertDontSee($password);
        }

        $this->assertDatabaseCount('webclient_os_logins', 1);
        $this->assertSame('replacement-secret', WebClientOsLogin::query()->sole()->password);

        $this->deleteJson('/webclient/os-login', [
            'peerId' => 'abc-123',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertNoContent();
        $this->deleteJson('/webclient/os-login', [
            'peerId' => 'abc-123',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertNoContent();
        $this->assertDatabaseCount('webclient_os_logins', 0);
    }

    public function test_plain_http_non_loopback_requests_are_refused(): void
    {
        $user = User::factory()->create();
        $this->withServerVariables([
            'HTTPS' => 'off',
            'SERVER_PORT' => 80,
            'HTTP_HOST' => 'console.example.test',
            'REMOTE_ADDR' => '192.0.2.10',
        ])->actingAs($user);

        $this->getJson('/webclient/os-login?peerId=123456')->assertForbidden();
        $this->postJson('/webclient/os-login/reveal', [
            'peerId' => '123456',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertForbidden();
        $this->putJson('/webclient/os-login', [
            'peerId' => '123456',
            'password' => 'test-only-password',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertForbidden();
        $this->deleteJson('/webclient/os-login', [
            'peerId' => '123456',
            'currentPassword' => self::ACCOUNT_PASSWORD,
        ])->assertForbidden();
        $this->assertDatabaseCount('webclient_os_logins', 0);
    }

    public function test_peer_password_and_reauthentication_boundaries_fail_closed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $invalid = [
            ['peerId' => '123 456', 'password' => 'secret', 'currentPassword' => self::ACCOUNT_PASSWORD],
            ['peerId' => '123456', 'password' => '', 'currentPassword' => self::ACCOUNT_PASSWORD],
            ['peerId' => '123456', 'password' => str_repeat('x', 1025), 'currentPassword' => self::ACCOUNT_PASSWORD],
            ['peerId' => '123456', 'password' => 'secret', 'currentPassword' => ''],
        ];
        foreach ($invalid as $payload) {
            $this->putJson('/webclient/os-login', $payload)->assertUnprocessable();
        }

        $this->assertDatabaseCount('webclient_os_logins', 0);
    }
}
