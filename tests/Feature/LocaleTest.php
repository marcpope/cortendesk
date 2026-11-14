<?php

use App\Console\Commands\LangCheck;
use App\Models\User;

/*
 * Console language (#111): the user's saved choice, then the language menu's
 * session choice, then Accept-Language, then APP_LOCALE.
 */

it('follows Accept-Language for a guest, matching the primary language', function () {
    $this->get('/login', ['Accept-Language' => 'de-DE,de;q=0.9'])->assertOk()->assertSee('<html lang="de"', false);
    $this->get('/login', ['Accept-Language' => 'pt-BR'])->assertSee('<html lang="pt-BR"', false);
});

it('falls back to APP_LOCALE when the browser asks for nothing we have', function () {
    config(['app.locale' => 'hu']);
    $this->get('/login', ['Accept-Language' => 'sv-SE'])->assertSee('<html lang="hu"', false);
    config(['app.locale' => 'en']);
    $this->get('/login', ['Accept-Language' => 'sv-SE'])->assertSee('<html lang="en"', false);
});

it('lets the saved choice beat the browser, and remembers the menu choice', function () {
    $user = User::factory()->create(['locale' => 'fr']);
    $this->actingAs($user)->get('/account', ['Accept-Language' => 'de'])->assertSee('<html lang="fr"', false);

    $this->post('/locale', ['locale' => 'es'], ['Referer' => url('/account')])->assertRedirect(url('/account'));
    expect($user->fresh()->locale)->toBe('es');
});

it('refuses unknown languages and never redirects off the host', function () {
    $this->post('/locale', ['locale' => 'xx'])->assertSessionHasErrors('locale');
    $this->post('/locale', ['locale' => 'de'], ['Referer' => 'https://evil.example/phish'])
        ->assertRedirect(route('login'));
});

it('picks plural forms per language', function () {
    expect(LangCheck::pluralForms('zh_CN'))->toBe(1)
        ->and(LangCheck::pluralForms('pt_BR'))->toBe(2)
        ->and(LangCheck::pluralForms('ru'))->toBe(3)
        ->and(LangCheck::pluralForms('pl'))->toBe(3);

    app()->setLocale('ru');
    expect(trans_choice('common.count_devices', 1))->not->toBe(trans_choice('common.count_devices', 5));
})->skip(fn () => ! is_file(lang_path('ru/common.php')), 'Russian not written yet');

it('keeps every catalog in step with English', function () {
    $this->artisan('lang:check')->assertSuccessful();
})->skip(fn () => count(glob(lang_path('*'), GLOB_ONLYDIR)) < count(User::LOCALES), 'catalogs still being written');
