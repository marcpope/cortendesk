<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebClientViewConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_exposes_authenticated_settings_endpoint_without_an_os_password(): void
    {
        $html = view('webclient', [
            'peerId' => '123456',
            'serverKeyB64' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'wsIdUrl' => 'wss://id.example.test',
            'wsRelayUrl' => 'wss://relay.example.test',
            'myId' => 'controller',
            'myName' => 'Controller',
        ])->render();

        $this->assertStringContainsString('osLoginUrl:', $html);
        $this->assertStringContainsString(str_replace('/', '\\/', route('webclient.os-login.show')), $html);
        $this->assertStringContainsString('csrfToken:', $html);
        $this->assertStringNotContainsString('osPassword', $html);
        $this->assertStringNotContainsString('remote-os-secret', $html);
    }
}
