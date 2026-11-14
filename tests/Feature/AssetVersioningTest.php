<?php

use App\Models\User;
use App\Support\Asset;

/*
|--------------------------------------------------------------------------
| Versioned asset URLs
|--------------------------------------------------------------------------
| Our CSS/JS filenames never change. Without a version in the URL, a browser
| that cached them under an earlier release keeps serving those bytes against
| freshly upgraded HTML, forever. The result is a console with the theme half
| applied — Bootstrap loads, our overrides do not — so the accent reverts to
| Bootstrap blue and custom layouts collapse. It presents as a browser bug.
*/

it('stamps the release version onto an asset url', function () {
    expect(Asset::url('assets/css/cortendesk.css'))
        ->toContain('assets/css/cortendesk.css?v='.config('cortendesk.api_version'));
});

it('serves the console stylesheet with a version query', function () {
    $this->actingAs(User::factory()->create())
        ->get('/devices')
        ->assertOk()
        ->assertSee('assets/css/cortendesk.css?v='.config('cortendesk.api_version'), false);
});

it('versions the sign-in page assets too', function () {
    // The guest layout is a separate file and was the easier one to forget.
    $this->get('/login')
        ->assertOk()
        ->assertSee('assets/css/cortendesk.css?v='.config('cortendesk.api_version'), false);
});

it('leaves no first-party stylesheet unversioned in either layout', function () {
    foreach (['resources/views/layouts/app.blade.php', 'resources/views/layouts/guest.blade.php'] as $layout) {
        $src = file_get_contents(base_path($layout));
        expect($src)->not->toMatch("/asset\('assets\/css\//");
    }
});
