<?php

use App\Contracts\TcpProbe;
use App\Models\Device;
use App\Models\Setting;
use App\Models\User;
use App\Services\FleetDiagnostics;
use Illuminate\Support\Facades\Cache;

/*
 * Fleet diagnostics. The export is meant to be pasted into a bug report, so
 * the assertions that matter most are the ones proving hosts, URLs and keys
 * are not in it.
 */

it('diagnostics is permission gated and uses bounded injected tcp probes', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    Setting::put('id_server', '10.0.0.5:21116');
    Setting::put('relay_server', 'relay.secret.test:21117');

    $fake = new class implements TcpProbe
    {
        public array $calls = [];

        public function check(string $host, int $port, float $timeout): array
        {
            $this->calls[] = [$host, $port, $timeout];

            return ['ok' => true, 'latency_ms' => 3, 'error' => null];
        }
    };
    $this->app->instance(TcpProbe::class, $fake);

    $this->actingAs($admin)->get('/diagnostics')->assertOk()->assertSee('Fleet diagnostics');
    $this->actingAs($user)->get('/diagnostics')->assertRedirect('/');

    $this->assertSame([
        ['10.0.0.5', 21116, 1.0],
        ['relay.secret.test', 21117, 1.0],
    ], $fake->calls);
});

it('report includes fleet scheduler mail and version signals', function () {
    $this->assertNull(app(FleetDiagnostics::class)->report()['smtp']['healthy']);

    $admin = User::factory()->admin()->create();
    Setting::put('smtp_enabled', '1');
    Setting::put('smtp_host', 'smtp.secret.test');
    Setting::put('smtp_from_address', 'desk@example.test');
    Cache::forever('cortendesk:diagnostics:scheduler-heartbeat', now()->toIso8601String());

    Device::create(['rustdesk_id' => '1', 'uuid' => 'one', 'status' => Device::STATUS_ACTIVE, 'version' => '1.4.0', 'last_online_at' => now()]);
    Device::create(['rustdesk_id' => '2', 'uuid' => 'two', 'status' => Device::STATUS_ACTIVE, 'version' => '1.3.0', 'last_online_at' => now()->subDays(2)]);

    $this->actingAs($admin)->get('/diagnostics')
        ->assertOk()
        ->assertSee('Scheduler')
        ->assertSee('SMTP')
        ->assertSee('Configured, but no send result has been observed.')
        ->assertSee('1.4.0')
        ->assertSee('Behind newest fleet version');

    $report = app(FleetDiagnostics::class)->report();
    $this->assertNull($report['smtp']['healthy']);
    $this->assertSame(1, $report['fleet']['silent_over_24h']);
    $this->assertTrue($report['services']['websocket_bridge']['ok']);

    config(['app.url' => '', 'cortendesk.ws_id_url' => '', 'cortendesk.ws_relay_url' => '']);
    $this->assertFalse(app(FleetDiagnostics::class)->report()['services']['websocket_bridge']['ok']);
});

it('sanitized export excludes hosts ips urls and keys', function () {
    $admin = User::factory()->admin()->create();
    Setting::put('id_server', '10.0.0.5:21116');
    Setting::put('relay_server', 'relay.secret.test:21117');
    Setting::put('public_key', 'VERY_SECRET_PUBLIC_KEY');
    config(['app.url' => 'https://desk.secret.test']);

    $response = $this->actingAs($admin)->get('/diagnostics/export')->assertOk();
    $response->assertHeader('Cache-Control', 'no-store, private');
    $json = $response->streamedContent();

    $this->assertStringNotContainsString('10.0.0.5', $json);
    $this->assertStringNotContainsString('relay.secret.test', $json);
    $this->assertStringNotContainsString('desk.secret.test', $json);
    $this->assertStringNotContainsString('VERY_SECRET_PUBLIC_KEY', $json);
    $this->assertStringContainsString('id_server', $json);
    $this->assertStringContainsString('configured', $json);
});

it('checks the bundled server over loopback, and other relays as configured (#114)', function () {
    config([
        'cortendesk.embedded_server' => true,
        'cortendesk.id_server' => 'relay.example.com:21116',
        'cortendesk.relay_server' => 'relay.example.com:21117',
        'app.url' => 'https://desk.example.com',
    ]);
    Setting::put('id_server', 'relay.example.com:31116');
    Setting::put('relay_servers', json_encode([
        ['address' => 'RELAY.example.com:31117'],
        ['address' => 'far.example.net:21117'],
    ]));

    $fake = new class implements TcpProbe
    {
        public array $calls = [];

        public function check(string $host, int $port, float $timeout): array
        {
            $this->calls[] = [$host, $port];

            return ['ok' => $host === '127.0.0.1' || $host === 'far.example.net', 'latency_ms' => 0, 'error' => null];
        }
    };
    $this->app->instance(TcpProbe::class, $fake);

    expect(app(FleetDiagnostics::class)->readiness())->toMatchArray(['ready' => true, 'id_server' => true, 'relay_server' => true])
        ->and($fake->calls)->toBe([['127.0.0.1', 21116], ['127.0.0.1', 21117], ['far.example.net', 21117]]);

    $report = app(FleetDiagnostics::class)->report();
    expect($report['services']['id_server']['host_label'])->toBe('bundled server over loopback')
        ->and($report['services']['id_server']['port'])->toBe(21116);
});

it('probes the configured address when the server is not bundled', function () {
    config(['cortendesk.embedded_server' => false, 'cortendesk.id_server' => 'relay.example.com:21116']);
    Setting::put('id_server', 'relay.example.com:21116');

    $fake = new class implements TcpProbe
    {
        public array $calls = [];

        public function check(string $host, int $port, float $timeout): array
        {
            $this->calls[] = [$host, $port];

            return ['ok' => true, 'latency_ms' => 0, 'error' => null];
        }
    };
    $this->app->instance(TcpProbe::class, $fake);

    app(FleetDiagnostics::class)->readiness();
    expect($fake->calls[0])->toBe(['relay.example.com', 21116]);
});
