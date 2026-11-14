<?php

use App\Models\User;

/*
 * Each throttle keeps its own counter. Unnamed limits key on the address
 * alone, so before every route got a prefix, heartbeats from an office behind
 * one NAT spent the sign-in budget, and /api/login counted each request twice.
 */

function limitLogin()
{
    return test()->postJson('/api/login', ['username' => 'nobody', 'password' => 'wrong', 'id' => '1', 'uuid' => 'u']);
}

it('lets heartbeats from the same address leave sign-in alone', function () {
    // Thirty devices behind one NAT, one heartbeat each.
    foreach (range(1, 30) as $n) {
        $this->postJson('/api/heartbeat', ['id' => (string) (100000000 + $n), 'uuid' => 'u'.$n, 'ver' => 1004090])->assertOk();
    }

    limitLogin()->assertOk();
});

it('allows twenty sign-in attempts a minute, not ten', function () {
    foreach (range(1, 20) as $_) {
        limitLogin()->assertOk();
    }

    limitLogin()->assertStatus(429);
});

it('limits heartbeats per device, not per address', function () {
    foreach (range(1, 120) as $_) {
        $this->postJson('/api/heartbeat', ['id' => '123456789', 'uuid' => 'u', 'ver' => 1004090]);
    }

    $this->postJson('/api/heartbeat', ['id' => '123456789', 'uuid' => 'u', 'ver' => 1004090])->assertStatus(429);
    $this->postJson('/api/heartbeat', ['id' => '987654321', 'uuid' => 'v', 'ver' => 1004090])->assertOk();
});

it('keeps the password-reset budget apart from client traffic', function () {
    foreach (range(1, 10) as $n) {
        $this->postJson('/api/heartbeat', ['id' => (string) $n, 'uuid' => 'u', 'ver' => 1004090]);
    }
    User::factory()->create();

    $this->post(route('password.email'), ['login' => 'someone'])->assertStatus(302);
});
