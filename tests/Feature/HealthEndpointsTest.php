<?php

use App\Contracts\TcpProbe;
use Illuminate\Support\Facades\Cache;

/*
 * Public health probes (PR #51). Boolean-only bodies, no session, and the
 * container healthcheck points at liveness — readiness depends on the relay,
 * and an external relay outage must not restart-loop the console.
 */

beforeEach(function () {
    // The limiter deliberately uses the persistent file store so probes work
    // through a database outage — which also means buckets survive between
    // tests unless cleared.
    Cache::store('file')->flush();
});

function fakeProbe(bool $ok): void
{
    app()->instance(TcpProbe::class, new class($ok) implements TcpProbe
    {
        public function __construct(private bool $ok) {}

        public function check(string $host, int $port, float $timeout): array
        {
            return ['ok' => $this->ok, 'latency_ms' => 1, 'error' => $this->ok ? null : 'refused'];
        }
    });
}

it('liveness answers with a boolean and nothing else', function () {
    $response = $this->getJson('/health/live')->assertOk();

    expect($response->json())->toBe(['live' => true]);
});

it('readiness returns 200 with dependency booleans when everything answers', function () {
    config(['cortendesk.id_server' => 'id.example.test:21116', 'cortendesk.relay_server' => 'relay.example.test:21117']);
    fakeProbe(true);

    $response = $this->getJson('/health/ready')->assertOk();

    expect($response->json())->toBe([
        'ready' => true, 'database' => true, 'id_server' => true, 'relay_server' => true,
    ]);
});

it('readiness returns 503 when a dependency is down, with booleans only', function () {
    config(['cortendesk.id_server' => 'id.example.test:21116']);
    fakeProbe(false);

    $response = $this->getJson('/health/ready')->assertStatus(503);

    expect($response->json('ready'))->toBeFalse()
        ->and($response->json())->not->toHaveKeys(['hosts', 'errors', 'version']);
});

it('readiness is not blocked by an absent relay configuration', function () {
    config(['cortendesk.id_server' => '', 'cortendesk.relay_server' => '']);
    fakeProbe(true);

    $this->getJson('/health/ready')->assertOk();
});

it('sets no session cookie on health responses', function () {
    $response = $this->getJson('/health/live');

    expect(collect($response->headers->getCookies())->pluck('name')->all())->toBe([]);
});

it('rate limits and keeps the body boolean-only at 429', function () {
    $limit = (int) config('health.probe_limits.live', 1);

    for ($i = 0; $i < $limit; $i++) {
        $this->getJson('/health/live')->assertOk();
    }

    $this->getJson('/health/live')
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('resets the probe window instead of sliding it (issue #74)', function () {
    // A container healthcheck every 30 s must never exhaust a per-minute
    // limit: the bucket expires one minute after it was created, no matter
    // how many probes land inside that minute.
    config(['health.probe_limits.live' => 3]);

    $this->getJson('/health/live')->assertOk();
    $this->travel(30)->seconds();
    $this->getJson('/health/live')->assertOk();
    $this->travel(31)->seconds();           // 61 s after the bucket was created
    $this->getJson('/health/live')->assertOk();
    $this->getJson('/health/live')->assertOk();
    $this->getJson('/health/live')->assertOk();
    $this->getJson('/health/live')->assertStatus(429);   // 4th inside the new window
});

it('keeps the container healthcheck on liveness, not readiness', function () {
    // Readiness probes the relay; with external servers a relay outage would
    // mark the container unhealthy and restart-loop the console.
    expect(file_get_contents(base_path('Dockerfile')))
        ->toContain('http://127.0.0.1:${CORTENDESK_HTTP_PORT:-8080}/health/live')
        ->not->toContain('8080}/health/ready');
});
