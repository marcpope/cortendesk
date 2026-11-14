<?php

use App\Models\User;
use App\Support\UpdateChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

afterEach(fn () => Cache::forget('cortendesk.latest_version'));

it('reports an upgrade when GitHub has a newer version', function () {
    config(['cortendesk.api_version' => '0.8.0-beta.1']);
    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());

    expect(UpdateChecker::upgradeAvailable())->toBe('0.8.0-beta.2');
});

it('reports no upgrade when versions match', function () {
    config(['cortendesk.api_version' => '0.8.0-beta.2']);
    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());

    expect(UpdateChecker::upgradeAvailable())->toBeNull();
});

it('reports no upgrade when running ahead of GitHub', function () {
    config(['cortendesk.api_version' => '0.9.0']);
    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());

    expect(UpdateChecker::upgradeAvailable())->toBeNull();
});

it('reports no upgrade when the check has no data', function () {
    config(['cortendesk.api_version' => '0.8.0-beta.1']);
    // nothing cached, and testing env never hits the network
    expect(UpdateChecker::upgradeAvailable())->toBeNull();
});

it('shows the update badge once, in the sidebar, for admins only', function () {
    config(['cortendesk.api_version' => '0.8.0-beta.1']);
    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());

    $html = $this->actingAs(User::factory()->admin()->create())
        ->get('/')->assertOk()->getContent();

    // One badge; its release-notes link appears again only in the About dialog.
    expect(substr_count($html, 'Update to v0.8.0-beta.2'))->toBe(1)
        ->and(substr_count($html, 'https://github.com/marcpope/cortendesk/releases/tag/v0.8.0-beta.2'))->toBe(2)
        ->and($html)->not->toContain('Update available')
        ->and($html)->not->toContain('Up-to-Date');

    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());
    $this->actingAs(User::factory()->create())
        ->get('/')->assertDontSee('Update to v0.8.0-beta.2');
});

it('makes no request and reports nothing when the update check is off (#89)', function () {
    config(['cortendesk.update_check' => false, 'cortendesk.api_version' => '0.8.0-beta.1']);
    // A cached newer version must not surface either.
    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());
    Http::fake();

    expect(UpdateChecker::enabled())->toBeFalse()
        ->and(UpdateChecker::latestVersion())->toBeNull()
        ->and(UpdateChecker::upgradeAvailable())->toBeNull();

    Http::assertNothingSent();
});

it('hides the badge and the release status line when the update check is off (#89)', function () {
    config(['cortendesk.update_check' => false, 'cortendesk.api_version' => '0.8.0-beta.1']);
    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());

    $this->actingAs(User::factory()->admin()->create())
        ->get('/')->assertOk()
        ->assertDontSee('Update to v0.8.0-beta.2')
        ->assertDontSee('Update available')
        ->assertDontSee('Up-to-Date')
        ->assertDontSee('Release check unavailable')
        ->assertSee('v0.8.0-beta.1');
});

it('shows the release status line when the update check is on', function () {
    config(['cortendesk.api_version' => '0.8.0-beta.2']);
    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());

    $this->actingAs(User::factory()->admin()->create())
        ->get('/')->assertOk()->assertSee('Up-to-Date');
});

it('shows the About dialog with version, release date and status', function () {
    config(['cortendesk.api_version' => '0.8.0-beta.2', 'cortendesk.released_at' => '2026-11-05']);
    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());

    $html = $this->actingAs(User::factory()->admin()->create())
        ->get('/')->assertOk()->getContent();

    expect($html)->toContain('id="rd-about"')
        ->and($html)->toContain('data-bs-target="#rd-about"')
        ->and($html)->toContain('Version 0.8.0-beta.2')
        ->and($html)->toContain('Released November 5, 2026')
        ->and($html)->toContain('Release notes for v0.8.0-beta.2')
        ->and($html)->toContain('<a href="https://github.com/marcpope" target="_blank" rel="noopener">Marc Pope</a>')
        ->and($html)->toContain('<a href="https://github.com/marcpope/cortendesk/blob/main/LICENSE" target="_blank" rel="noopener">AGPL-3.0 license</a>')
        ->and($html)->not->toContain('A new version is available');
});

it('says a new version is available in the About dialog', function () {
    config(['cortendesk.api_version' => '0.8.0-beta.1']);
    Cache::put('cortendesk.latest_version', '0.8.0-beta.2', now()->addHour());

    $this->actingAs(User::factory()->admin()->create())
        ->get('/')->assertOk()
        ->assertSee('A new version is available: v0.8.0-beta.2')
        ->assertSee('Release notes for v0.8.0-beta.2');
});

it('leaves the About dialog out for users without settings access', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')->assertOk()->assertDontSee('id="rd-about"', false);
});
