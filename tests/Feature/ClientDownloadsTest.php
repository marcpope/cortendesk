<?php

use App\Livewire\ClientDownloadManager;
use App\Models\ClientDownload;
use App\Models\Setting;
use App\Models\User;
use App\Support\ClientPlatform;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Client downloads (issue #43, PR #44). The public routes hand files to
 * anonymous visitors, so what matters most: only published rows exist as far
 * as an outsider can tell, and nothing served can execute in the browser.
 */

function makeDownload(array $overrides = []): ClientDownload
{
    Storage::fake(ClientDownload::DISK);
    $name = $overrides['filename'] ?? 'stored-abc123.exe';
    Storage::disk(ClientDownload::DISK)->put($name, 'binary-bytes');

    return ClientDownload::create(array_merge([
        'label' => 'Support client',
        'platform' => 'windows',
        'arch' => 'x86_64',
        'filename' => $name,
        'original_name' => 'rustdesk-support.exe',
        'size' => 12,
        'is_published' => true,
        'sort_order' => 0,
    ], $overrides));
}

it('serves the public page without auth and lists only published builds', function () {
    makeDownload(['label' => 'Public build']);
    makeDownload(['label' => 'Staged build', 'is_published' => false, 'filename' => 'stored-def456.exe']);

    $this->get('/downloads')
        ->assertOk()
        ->assertSee('Public build')
        ->assertDontSee('Staged build');
});

it('streams a published build as a nosniff attachment', function () {
    $download = makeDownload();

    $response = $this->get('/downloads/'.$download->id)->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Content-Type', 'application/octet-stream');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
    expect($response->streamedContent())->toBe('binary-bytes');
    expect($download->fresh()->download_count)->toBe(1);
});

it('hands the file to nginx with X-Accel-Redirect when the image sets it up', function () {
    config(['cortendesk.downloads_accel' => '/_cortendesk_downloads']);
    $download = makeDownload(['original_name' => 'Support – Client.exe']);

    $response = $this->get('/downloads/'.$download->id)->assertOk();

    $response->assertHeader('X-Accel-Redirect', '/_cortendesk_downloads/stored-abc123.exe');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Content-Type', 'application/octet-stream');
    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('filename="Support _ Client.exe"')
        ->toContain("filename*=utf-8''Support%20%E2%80%93%20Client.exe")
        ->and($response->getContent())->toBe('')
        ->and($download->fresh()->download_count)->toBe(1);
});

it('still 404s an unpublished build when nginx sends the files', function () {
    config(['cortendesk.downloads_accel' => '/_cortendesk_downloads']);
    $download = makeDownload(['is_published' => false]);

    $this->get('/downloads/'.$download->id)
        ->assertNotFound()
        ->assertHeaderMissing('X-Accel-Redirect');
});

it('404s an unpublished build so outsiders learn nothing about staging', function () {
    $download = makeDownload(['is_published' => false]);

    $this->get('/downloads/'.$download->id)->assertNotFound();
});

it('gates the manager like the settings area', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/client-downloads')->assertOk();
    $this->actingAs(User::factory()->create())->get('/client-downloads')->assertRedirect('/');
});

it('uploads a build, stores it under a random name, and records a hash', function () {
    Storage::fake(ClientDownload::DISK);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ClientDownloadManager::class)
        ->call('create')
        ->set('file', UploadedFile::fake()->create('rustdesk-1.4.0-aarch64.apk', 512))
        ->call('save')
        ->assertHasNoErrors();

    $row = ClientDownload::sole();
    expect($row->platform)->toBe('android')
        ->and($row->original_name)->toBe('rustdesk-1.4.0-aarch64.apk')
        // Never stored under the client-chosen name.
        ->and($row->filename)->not->toBe('rustdesk-1.4.0-aarch64.apk')
        ->and($row->sha256)->not->toBeEmpty()
        ->and(Storage::disk(ClientDownload::DISK)->exists($row->filename))->toBeTrue();
});

it('refuses an extension outside the allowlist', function () {
    Storage::fake(ClientDownload::DISK);
    expect(ClientPlatform::extensionAllowed('evil.php'))->toBeFalse();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ClientDownloadManager::class)
        ->call('create')
        ->set('file', UploadedFile::fake()->create('evil.php', 10))
        ->call('save')
        ->assertHasErrors('file');

    expect(ClientDownload::count())->toBe(0);
});

it('deletes the bytes with the row', function () {
    $download = makeDownload();
    $file = $download->filename;

    $download->delete();

    expect(Storage::disk(ClientDownload::DISK)->exists($file))->toBeFalse();
});

it('links from the sign-in page only when enabled and something is published', function () {
    // Nothing published: no link, whatever the setting says.
    Setting::put('downloads_on_login', '1');
    $this->get('/login')->assertDontSee('Download the client');

    makeDownload();
    $this->get('/login')->assertSee('Download the client');

    Setting::put('downloads_on_login', '0');
    $this->get('/login')->assertDontSee('Download the client');
});

it('accepts a 90 MB AppImage under the default 512 MB limit (#84)', function () {
    Storage::fake(ClientDownload::DISK);

    expect(ClientDownloadManager::maxKilobytes())->toBe(512 * 1024)
        // Livewire's temp-upload rule stays 1 MB looser than the app's limit.
        ->and(config('livewire.temporary_file_upload.rules'))->toContain('max:'.(513 * 1024));

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ClientDownloadManager::class)
        ->call('create')
        ->assertSee('Up to 512 MB')
        ->set('file', UploadedFile::fake()->create('rustdesk-1.4.2-x86_64.AppImage', 90000))
        ->call('save')
        ->assertHasNoErrors();

    $row = ClientDownload::sole();
    expect($row->platform)->toBe('linux')
        ->and($row->size)->toBe(90000 * 1024);
});

it('names the configured limit when a build is too large (#84)', function () {
    Storage::fake(ClientDownload::DISK);
    config(['cortendesk.downloads_max_kb' => 2048]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ClientDownloadManager::class)
        ->call('create')
        ->assertSee('Up to 2 MB')
        ->set('file', UploadedFile::fake()->create('rustdesk.exe', 2500))
        ->call('save')
        ->assertHasErrors(['file' => 'max'])
        ->assertSee('larger than the 2 MB upload limit');

    expect(ClientDownload::count())->toBe(0);
});
