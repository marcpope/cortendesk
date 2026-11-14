<?php

use App\Livewire\BackupManager;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Backups\BackupService;
use App\Services\Backups\FtpTarget;
use App\Services\Backups\S3Target;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function removeBackupTestTree(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function backupAdmin(): User
{
    $user = User::create(['username' => 'backup-admin', 'password' => 'secret-password', 'is_admin' => true]);
    test()->actingAs($user);

    return $user;
}

function backupViewer(): User
{
    $role = Role::create([
        'name' => 'Settings viewer',
        'permissions' => Role::normalizePermissions(['setting' => 'r']),
    ]);

    return User::factory()->create(['role_id' => $role->id]);
}

/** Point the app at another APP_KEY the way a different install would have one. */
function swapAppKey(string $key): void
{
    config(['app.key' => $key]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstances();
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/cortendesk-backup-test-'.bin2hex(random_bytes(4));
    mkdir($this->dir.'/rustdesk', 0700, true);
    file_put_contents($this->dir.'/rustdesk/id_ed25519', 'SECRET-KEY');
    file_put_contents($this->dir.'/rustdesk/id_ed25519.pub', 'PUBLIC-KEY');
    config([
        'cortendesk.backups_path' => $this->dir.'/backups',
        'cortendesk.rustdesk_dir' => $this->dir.'/rustdesk',
    ]);
});

afterEach(function () {
    removeBackupTestTree($this->dir);
});

it('writes one archive holding the database, the app key and the server files', function () {
    Setting::put('rdgen_url', 'https://rdgen.example');

    $backup = app(BackupService::class)->create();

    expect($backup['path'])->toBeFile()
        ->and($backup['name'])->toMatch(BackupService::NAME_PATTERN)
        ->and($backup['path'].'.json')->toBeFile();

    $tar = new PharData($backup['path']);
    expect(isset($tar['manifest.json']))->toBeTrue()
        ->and(isset($tar['db/settings.ndjson.gz']))->toBeTrue()
        ->and(isset($tar['db/users.ndjson.gz']))->toBeTrue()
        ->and(isset($tar['files/app_key']))->toBeTrue()
        ->and(isset($tar['files/rustdesk/id_ed25519']))->toBeTrue()
        ->and((string) $tar['files/app_key']->getContent())->toBe((string) config('app.key'))
        ->and((string) $tar['files/rustdesk/id_ed25519']->getContent())->toBe('SECRET-KEY');

    $manifest = $backup['manifest'];
    expect($manifest['format'])->toBe(1)
        ->and($manifest['tables'])->toHaveKey('settings')
        ->and($manifest['tables'])->toHaveKey('migrations')
        ->and($manifest['tables'])->not->toHaveKey('cache')
        ->and($manifest['tables'])->not->toHaveKey('sessions')
        ->and($manifest['files'])->toContain('rustdesk/id_ed25519.pub');
});

it('writes and reads archives under a dotted path without moving the working directory', function () {
    // PR #106: PharData probes dotted components such as a domain-named
    // site root, which open_basedir hosts reject. Archives are opened by bare
    // name from their own directory; the cwd must come back afterwards.
    config(['cortendesk.backups_path' => $this->dir.'/example.com/backups']);
    $cwd = getcwd();

    $service = app(BackupService::class);
    $backup = $service->create();

    expect($service->readManifest($backup['path'])['app'])->toBe('cortendesk')
        ->and(getcwd())->toBe($cwd);
});

it('restores every data table and stages the server files', function () {
    $service = app(BackupService::class);

    Setting::put('rdgen_url', 'before');
    $kept = User::create(['username' => 'kept', 'password' => 'x']);
    $backup = $service->create();

    Setting::put('rdgen_url', 'after');
    Setting::put('brand_new_key', 'added later');
    $kept->delete();
    User::create(['username' => 'later', 'password' => 'x']);

    $result = $service->restore($backup['path']);

    expect(Setting::get('rdgen_url'))->toBe('before')
        ->and(Setting::get('brand_new_key'))->toBeNull()
        ->and(User::where('username', 'kept')->exists())->toBeTrue()
        ->and(User::where('username', 'later')->exists())->toBeFalse()
        ->and($result['rows'])->toBeGreaterThan(0)
        ->and($result['rekeyed'])->toBeFalse()
        ->and($result['files'])->toContain('id_ed25519')
        ->and($result['restart_needed'])->toBeTrue()
        ->and($this->dir.'/rustdesk/id_ed25519.restore')->toBeFile()
        ->and(file_get_contents($this->dir.'/rustdesk/id_ed25519.restore'))->toBe('SECRET-KEY')
        // The live file is never touched while the server may hold it open.
        ->and(file_get_contents($this->dir.'/rustdesk/id_ed25519'))->toBe('SECRET-KEY');
});

it('re-keys encrypted values when the archive came from another app key', function () {
    $original = (string) config('app.key');
    $other = 'base64:'.base64_encode(random_bytes(32));

    swapAppKey($other);
    Setting::put('smtp_password', Crypt::encryptString('hunter2'));
    $user = User::create(['username' => 'totp', 'password' => 'x']);
    $user->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $backup = app(BackupService::class)->create();

    swapAppKey($original);
    $result = app(BackupService::class)->restore($backup['path']);

    expect($result['rekeyed'])->toBeTrue()
        ->and(Crypt::decryptString((string) Setting::get('smtp_password')))->toBe('hunter2')
        ->and(User::find($user->id)->totp_secret)->toBe('JBSWY3DPEHPK3PXP');
});

it('refuses a file that is not a backup and leaves the data alone', function () {
    Setting::put('rdgen_url', 'untouched');
    file_put_contents($junk = $this->dir.'/junk.tar', 'this is not a tar');

    expect(fn () => app(BackupService::class)->restore($junk))
        ->toThrow(RuntimeException::class, 'not a CortenDesk backup')
        ->and(Setting::get('rdgen_url'))->toBe('untouched');
});

it('keeps only the newest backups', function () {
    $service = app(BackupService::class);
    $dir = $service->directory();
    foreach (['20260101-000000', '20260102-000000', '20260103-000000'] as $ts) {
        touch("{$dir}/cortendesk-backup-{$ts}.tar");
    }

    $deleted = $service->applyRetention(['daily' => 2, 'weekly' => 0, 'monthly' => 0]);

    expect($deleted)->toBe(['cortendesk-backup-20260101-000000.tar'])
        ->and("{$dir}/cortendesk-backup-20260101-000000.tar")->not->toBeFile()
        ->and("{$dir}/cortendesk-backup-20260103-000000.tar")->toBeFile()
        ->and($service->list())->toHaveCount(2);
});

it('owes a scheduled backup once a day, by the calendar', function () {
    $now = Carbon::parse('2026-10-14 03:30');

    expect(BackupService::due(null, $now))->toBeTrue()
        ->and(BackupService::due(Carbon::parse('2026-10-13 03:30'), $now))->toBeTrue()
        ->and(BackupService::due(Carbon::parse('2026-10-13 23:59'), $now))->toBeTrue()
        ->and(BackupService::due(Carbon::parse('2026-10-14 01:00'), $now))->toBeFalse();
});

it('keeps the daily, weekly and monthly tiers at once', function () {
    // 100 daily backups ending Monday 2026-10-12.
    $entries = [];
    for ($i = 0; $i < 100; $i++) {
        $at = Carbon::parse('2026-10-12 03:30')->subDays($i);
        $entries[] = ['name' => 'cortendesk-backup-'.$at->format('Ymd-His').'.tar', 'created_at' => $at];
    }
    $name = fn (string $day) => 'cortendesk-backup-'.Carbon::parse($day)->format('Ymd').'-033000.tar';

    $delete = BackupService::prunable($entries, ['daily' => 7, 'weekly' => 8, 'monthly' => 6]);
    $kept = array_values(array_diff(array_column($entries, 'name'), $delete));

    // the newest seven
    foreach (['2026-10-12', '2026-10-11', '2026-10-10', '2026-10-09', '2026-10-08', '2026-10-07', '2026-10-06'] as $d) {
        expect($kept)->toContain($name($d));
    }
    expect($delete)->toContain($name('2026-10-04'));
    // the Monday of each of the last eight ISO weeks, and not the ninth
    foreach (['2026-10-05', '2026-09-28', '2026-09-21', '2026-09-14', '2026-09-07', '2026-08-31', '2026-08-24'] as $d) {
        expect($kept)->toContain($name($d));
    }
    expect($delete)->toContain($name('2026-08-17'))->and($delete)->toContain($name('2026-08-25'));
    // the first backup of each month in range (the run starts 2026-07-05)
    foreach (['2026-10-01', '2026-09-01', '2026-08-01', '2026-07-05'] as $d) {
        expect($kept)->toContain($name($d));
    }
    expect($delete)->toContain($name('2026-07-06'));
    expect($kept)->toHaveCount(7 + 7 + 4)
        ->and($delete)->toBe(array_values(array_filter(array_column(array_reverse($entries), 'name'), fn ($n) => ! in_array($n, $kept, true))));

    // a tier at 0 is off; all at 0 keeps everything
    expect(BackupService::prunable($entries, ['daily' => 0, 'weekly' => 0, 'monthly' => 1]))->toHaveCount(99)
        ->and(BackupService::prunable($entries, ['daily' => 0, 'weekly' => 0, 'monthly' => 0]))->toBe([]);
});

it('runs on schedule only when enabled and due', function () {
    $service = app(BackupService::class);

    Setting::put('backup_enabled', '0');
    Artisan::call('cortendesk:backup --scheduled');
    expect($service->list())->toBeEmpty()
        ->and(Artisan::output())->toContain('off');

    Setting::put('backup_enabled', '1');
    Artisan::call('cortendesk:backup --scheduled');
    expect($service->list())->toHaveCount(1)
        ->and(Setting::get('backup_last_scheduled_at'))->not->toBeNull();

    Artisan::call('cortendesk:backup --scheduled');
    expect($service->list())->toHaveCount(1)
        ->and(Artisan::output())->toContain('Not due');

    $last = json_decode((string) Setting::get('backup_last_result'), true);
    expect($last['ok'])->toBeTrue()->and($last['trigger'])->toBe('scheduled');
});

it('applies retention after a scheduled run', function () {
    $dir = app(BackupService::class)->directory();
    foreach (['20260101-000000', '20260102-000000', '20260103-000000'] as $ts) {
        touch("{$dir}/cortendesk-backup-{$ts}.tar");
    }
    Setting::put('backup_keep_daily', '2');
    Setting::put('backup_keep_weekly', '0');
    Setting::put('backup_keep_monthly', '0');

    Artisan::call('cortendesk:backup');

    $names = array_column(app(BackupService::class)->list(), 'name');
    expect($names)->toHaveCount(2)
        ->and($names)->not->toContain('cortendesk-backup-20260101-000000.tar')
        ->and($names)->not->toContain('cortendesk-backup-20260102-000000.tar');
});

it('mirrors the local list to S3: SigV4 PUT for what is missing, DELETE for what is gone', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response('<?xml version="1.0"?><ListBucketResult><IsTruncated>false</IsTruncated>'
                .'<Contents><Key>cd/cortendesk-backup-20200101-000000.tar</Key></Contents>'
                .'<Contents><Key>cd/cortendesk-backup-20200102-000000.tar</Key></Contents>'
                .'<Contents><Key>cd/cortendesk-backup-20200103-000000.tar</Key></Contents>'
                .'<Contents><Key>cd/other/not-ours.tar</Key></Contents>'
                .'</ListBucketResult>', 200);
        }

        return Http::response('', 200);
    });

    // Locally: 0102 still kept, 0101 pruned by retention (daily 2 with the new
    // one), 0103 was deleted on the tab earlier and is only in the bucket.
    $dir = app(BackupService::class)->directory();
    touch("{$dir}/cortendesk-backup-20200101-000000.tar");
    touch("{$dir}/cortendesk-backup-20200102-000000.tar");

    Setting::put('backup_target', 's3');
    Setting::put('backup_s3_bucket', 'cd-backups');
    Setting::put('backup_s3_region', 'eu-west-1');
    Setting::put('backup_s3_prefix', 'cd');
    Setting::put('backup_s3_access_key', 'AKIAEXAMPLE');
    Setting::put('backup_s3_secret_key', Crypt::encryptString('s3cr3t'));
    Setting::put('backup_keep_daily', '2');
    Setting::put('backup_keep_weekly', '0');
    Setting::put('backup_keep_monthly', '0');

    $code = Artisan::call('cortendesk:backup');

    expect($code)->toBe(0)->and(Artisan::output())->toContain('to S3 bucket cd-backups/cd');

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
        && str_starts_with($r->url(), 'https://cd-backups.s3.eu-west-1.amazonaws.com/cd/cortendesk-backup-')
        && ! str_contains($r->url(), '2020010')
        && str_starts_with($r->header('Authorization')[0] ?? '', 'AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE/')
        && str_contains($r->header('Authorization')[0] ?? '', 'SignedHeaders=host;x-amz-content-sha256;x-amz-date')
        && ($r->header('x-amz-content-sha256')[0] ?? '') !== '');
    // Already there, not uploaded again.
    Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '20200102'));

    // Pruned here and deleted on the tab: both come down. The kept one and the
    // stranger in the sub-folder are not touched.
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
        && str_ends_with($r->url(), '/cd/cortendesk-backup-20200101-000000.tar'));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
        && str_ends_with($r->url(), '/cd/cortendesk-backup-20200103-000000.tar'));
    Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE'
        && str_ends_with($r->url(), '/cd/cortendesk-backup-20200102-000000.tar'));
    Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'not-ours'));
});

it('uploads again when the off-site copy is a different size, as one cut short would be', function () {
    $dir = app(BackupService::class)->directory();
    file_put_contents("{$dir}/cortendesk-backup-20200101-000000.tar", str_repeat('x', 100));
    file_put_contents("{$dir}/cortendesk-backup-20200102-000000.tar", str_repeat('x', 100));
    file_put_contents("{$dir}/cortendesk-backup-20200103-000000.tar", str_repeat('x', 100));

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            // 0101 complete, 0102 cut short, 0103 without a size.
            return Http::response('<?xml version="1.0"?><ListBucketResult><IsTruncated>false</IsTruncated>'
                .'<Contents><Key>cortendesk-backup-20200101-000000.tar</Key><Size>100</Size></Contents>'
                .'<Contents><Key>cortendesk-backup-20200102-000000.tar</Key><Size>37</Size></Contents>'
                .'<Contents><Key>cortendesk-backup-20200103-000000.tar</Key></Contents>'
                .'</ListBucketResult>', 200);
        }

        return Http::response('', 200);
    });

    $target = new S3Target('cd-backups', 'us-east-1', 'ak', 'sk');
    expect($target->list())->toBe([
        'cortendesk-backup-20200101-000000.tar' => 100,
        'cortendesk-backup-20200102-000000.tar' => 37,
        'cortendesk-backup-20200103-000000.tar' => null,
    ]);

    $sync = app(BackupService::class)->syncRemote($target);

    expect($sync['uploaded'])->toBe(['cortendesk-backup-20200102-000000.tar'])
        ->and($sync['deleted'])->toBe([]);
});

it('leaves the off-site target alone while another run is copying to it', function () {
    Http::fake(['*' => Http::response('<ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>', 200)]);
    // Another process holding the lock file; a separate open file
    // description conflicts with flock the same way.
    $lock = fopen(storage_path('framework/backup-copy.lock'), 'c');
    expect(flock($lock, LOCK_EX | LOCK_NB))->toBeTrue();

    try {
        expect(fn () => app(BackupService::class)->syncRemote(new S3Target('cd-backups', 'us-east-1', 'ak', 'sk')))
            ->toThrow(RuntimeException::class, 'Another run is copying');
        Http::assertNothingSent();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    expect(app(BackupService::class)->syncRemote(new S3Target('cd-backups', 'us-east-1', 'ak', 'sk')))
        ->toBe(['uploaded' => [], 'deleted' => []]);
});

it('removes the off-site copy when a backup is deleted on the tab', function () {
    backupAdmin();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response('<?xml version="1.0"?><ListBucketResult><IsTruncated>false</IsTruncated>'
                .'<Contents><Key>cortendesk-backup-20200102-000000.tar</Key></Contents></ListBucketResult>', 200);
        }

        return Http::response('', 200);
    });
    Setting::put('backup_target', 's3');
    Setting::put('backup_s3_bucket', 'cd-backups');
    Setting::put('backup_s3_access_key', 'AKIAEXAMPLE');
    Setting::put('backup_s3_secret_key', Crypt::encryptString('s3cr3t'));
    $dir = app(BackupService::class)->directory();
    touch("{$dir}/cortendesk-backup-20200102-000000.tar");
    touch("{$dir}/cortendesk-backup-20200105-000000.tar");

    Livewire::test(BackupManager::class)
        ->call('delete', 'cortendesk-backup-20200102-000000.tar')
        ->assertSet('notice.tone', 'info');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/cortendesk-backup-20200102-000000.tar'));

    // Not at the target: nothing to delete there, no error.
    Livewire::test(BackupManager::class)
        ->call('delete', 'cortendesk-backup-20200105-000000.tar')
        ->assertSet('notice.tone', 'info');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '20200105'));
    expect(app(BackupService::class)->list())->toBeEmpty();
});

it('keeps the local copy and reports when the off-site copy fails', function () {
    Http::fake(['*' => Http::response('<Error><Code>AccessDenied</Code><Message>nope</Message></Error>', 403)]);

    Setting::put('backup_target', 's3');
    Setting::put('backup_s3_bucket', 'cd-backups');
    Setting::put('backup_s3_access_key', 'AKIAEXAMPLE');
    Setting::put('backup_s3_secret_key', Crypt::encryptString('s3cr3t'));

    $code = Artisan::call('cortendesk:backup');

    expect($code)->not->toBe(0)
        ->and(app(BackupService::class)->list())->toHaveCount(1);
    $last = json_decode((string) Setting::get('backup_last_result'), true);
    expect($last['ok'])->toBeFalse()->and($last['message'])->toContain('AccessDenied');
});

it('signs path-style requests against a custom endpoint', function () {
    Http::fake(['*' => Http::response('', 200)]);
    file_put_contents($file = $this->dir.'/x.tar', 'x');

    (new S3Target('bucket', 'us-east-1', 'ak', 'sk', 'http://minio.local:9000', 'p', true))->put($file, 'cortendesk-backup-20260101-000000.tar');

    Http::assertSent(fn (Request $r) => $r->url() === 'http://minio.local:9000/bucket/p/cortendesk-backup-20260101-000000.tar');
});

it('shows the connection test result next to the button, not in the notice area', function () {
    backupAdmin();
    Http::fakeSequence()
        ->push('<ListBucketResult></ListBucketResult>', 200)
        ->push('<Error><Code>AccessDenied</Code><Message>nope</Message></Error>', 403);

    $component = Livewire::test(BackupManager::class)
        ->set('target', 's3')
        ->set('s3Bucket', 'cd-backups')
        ->set('s3AccessKey', 'AKIAEXAMPLE')
        ->set('s3SecretKey', 's3cr3t')
        ->call('testTarget')
        ->assertSet('testResult.tone', 'success')
        ->assertSet('notice', null)
        ->assertSet('saved', false)
        ->assertSee('Saved. Connected to S3 bucket cd-backups.');

    $component->call('testTarget')
        ->assertSet('testResult.tone', 'danger')
        ->assertSee('Saved, but the test failed:')
        ->assertSee('AccessDenied');

    // A plain save clears a result that no longer describes the settings.
    $component->call('save')->assertSet('testResult', null)->assertSet('saved', true);
});

it('sends FTP data connections to the host entered unless both addresses are public', function () {
    // NAT or a container: the server advertises a private address.
    expect(FtpTarget::usePasvAddress('10.0.0.5', '203.0.113.7'))->toBeFalse()
        ->and(FtpTarget::usePasvAddress('172.22.0.9', '172.22.0.9'))->toBeFalse()
        // A NAS on the LAN that advertises its WAN address.
        ->and(FtpTarget::usePasvAddress('8.8.4.4', '192.168.1.20'))->toBeFalse()
        // Reserved and shared ranges are not public either.
        ->and(FtpTarget::usePasvAddress('192.0.2.1', '8.8.8.8'))->toBeFalse()
        ->and(FtpTarget::usePasvAddress('100.64.0.1', '8.8.8.8'))->toBeFalse()
        // An unresolved name never matches.
        ->and(FtpTarget::usePasvAddress('8.8.4.4', 'ftp.example.com'))->toBeFalse()
        // A deliberate public data address, e.g. behind a load balancer.
        ->and(FtpTarget::usePasvAddress('8.8.4.4', '8.8.8.8'))->toBeTrue();
});

it('saves the schedule and off-site settings from the Backups tab', function () {
    backupAdmin();

    Livewire::test(BackupManager::class)
        ->set('enabled', true)
        ->set('keepDaily', 14)
        ->set('keepWeekly', 0)
        ->set('keepMonthly', 12)
        ->set('target', 's3')
        ->set('s3Bucket', 'cd-backups')
        ->set('s3SecretKey', 'shh')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true)
        ->assertSet('s3SecretKey', '')
        ->assertSet('s3SecretKeySet', true);

    expect(Setting::get('backup_keep_daily'))->toBe('14')
        ->and(Setting::get('backup_keep_weekly'))->toBe('0')
        ->and(Setting::get('backup_keep_monthly'))->toBe('12')
        ->and(BackupService::settings()['keep'])->toBe(['daily' => 14, 'weekly' => 0, 'monthly' => 12])
        ->and(Setting::get('backup_target'))->toBe('s3')
        ->and(Crypt::decryptString((string) Setting::get('backup_s3_secret_key')))->toBe('shh');

    // A blank secret on the next save keeps the stored one.
    Livewire::test(BackupManager::class)->set('s3Bucket', 'renamed')->call('save')->assertHasNoErrors();
    expect(Crypt::decryptString((string) Setting::get('backup_s3_secret_key')))->toBe('shh')
        ->and(Setting::get('backup_s3_bucket'))->toBe('renamed');
});

it('shows the tier defaults and bounds what can be typed', function () {
    backupAdmin();

    Livewire::test(BackupManager::class)
        ->assertSet('keepDaily', 7)
        ->assertSet('keepWeekly', 8)
        ->assertSet('keepMonthly', 6)
        ->set('keepWeekly', 400)
        ->call('save')
        ->assertHasErrors('keepWeekly');
});

it('needs a host or bucket before copies can be turned on', function () {
    backupAdmin();

    Livewire::test(BackupManager::class)->set('target', 'ftp')->call('save')->assertHasErrors('ftpHost');
    Livewire::test(BackupManager::class)->set('target', 's3')->call('save')->assertHasErrors('s3Bucket');
});

it('backs up and restores from the Backups tab', function () {
    backupAdmin();
    Setting::put('rdgen_url', 'before');

    $component = Livewire::test(BackupManager::class)->call('backupNow')->assertSet('notice.tone', 'success');
    $name = app(BackupService::class)->list()[0]['name'];

    Setting::put('rdgen_url', 'after');
    $component->call('restore', $name)->assertSet('notice.tone', 'success');

    expect(Setting::get('rdgen_url'))->toBe('before');

    $component->call('delete', $name);
    expect(app(BackupService::class)->list())->toBeEmpty();
});

it('refuses every backup action to a view-only settings role', function () {
    $viewer = backupViewer();

    $this->actingAs($viewer)->get(route('settings'))->assertOk();

    Livewire::actingAs($viewer)->test(BackupManager::class)->assertSet('enabled', true)
        ->call('backupNow')->assertForbidden();
    Livewire::actingAs($viewer)->test(BackupManager::class)->call('save')->assertForbidden();
    Livewire::actingAs($viewer)->test(BackupManager::class)->call('restore', 'cortendesk-backup-20260101-000000.tar')->assertForbidden();
    Livewire::actingAs($viewer)->test(BackupManager::class)->call('delete', 'cortendesk-backup-20260101-000000.tar')->assertForbidden();

    touch(app(BackupService::class)->directory().'/cortendesk-backup-20260101-000000.tar');
    // console-can bounces to the overview rather than rendering a 403.
    $this->actingAs($viewer)->get(route('settings.backups.download', 'cortendesk-backup-20260101-000000.tar'))
        ->assertRedirect(route('overview'));
});

it('lets a manager download a backup and 404s on anything else', function () {
    backupAdmin();
    $backup = app(BackupService::class)->create();

    $this->get(route('settings.backups.download', $backup['name']))
        ->assertOk()
        ->assertHeader('content-type', 'application/x-tar');

    $this->get(route('settings.backups.download', 'cortendesk-backup-20260101-000000.tar'))->assertNotFound();
    $this->get('/settings/backups/..%2F..%2F.env')->assertNotFound();
});

it('restores from an uploaded archive', function () {
    backupAdmin();
    Setting::put('rdgen_url', 'before');
    $backup = app(BackupService::class)->create();
    $bytes = file_get_contents($backup['path']);
    app(BackupService::class)->delete($backup['name']);
    Setting::put('rdgen_url', 'after');

    Livewire::test(BackupManager::class)
        ->set('upload', UploadedFile::fake()->createWithContent('from-elsewhere.tar', $bytes))
        ->call('restoreUpload')
        ->assertHasNoErrors()
        ->assertSet('notice.tone', 'success');

    expect(Setting::get('rdgen_url'))->toBe('before')
        // The upload is kept as a local backup under a name of ours.
        ->and(app(BackupService::class)->list())->toHaveCount(1)
        ->and(app(BackupService::class)->list()[0]['name'])->toEndWith('-upload.tar');
});

it('rejects an upload that is not a backup', function () {
    backupAdmin();
    Setting::put('rdgen_url', 'untouched');

    Livewire::test(BackupManager::class)
        ->set('upload', UploadedFile::fake()->createWithContent('junk.tar', 'nope'))
        ->call('restoreUpload')
        ->assertSet('notice.tone', 'danger');

    expect(Setting::get('rdgen_url'))->toBe('untouched')
        ->and(app(BackupService::class)->list())->toBeEmpty();
});

/** Apprise on, delivering to a faked endpoint, with only the backup events picked. */
function backupApprise(): void
{
    Setting::put('apprise_enabled', '1');
    Setting::put('apprise_endpoint', Crypt::encryptString('https://apprise.example.test'));
    Setting::put('apprise_delivery_mode', 'config');
    Setting::put('apprise_config_key', Crypt::encryptString('ops'));
    Setting::put('apprise_event_backup_completed', '1');
    Setting::put('apprise_event_backup_failed', '1');
}

it('logs a scheduled run as system and notifies that it completed', function () {
    // Issue #104: scheduled runs left no trace outside the Backups tab.
    backupApprise();
    Http::fake(['apprise.example.test/*' => Http::response(['result' => 'success'])]);
    Setting::put('backup_enabled', '1');

    Artisan::call('cortendesk:backup --scheduled');

    $audit = \App\Models\ConsoleAudit::where('action', 'backup.create')->sole();
    expect($audit->username)->toBe('system')
        ->and($audit->user_id)->toBeNull()
        ->and($audit->summary)->toStartWith('Scheduled backup: Wrote cortendesk-backup-');

    $this->assertDatabaseHas('notification_deliveries', ['event' => 'backup.completed', 'status' => 'sent']);
    $this->assertDatabaseMissing('notification_deliveries', ['event' => 'backup.failed']);
});

it('reports a failed off-site copy as a failed backup', function () {
    backupApprise();
    Http::fake([
        'apprise.example.test/*' => Http::response(['result' => 'success']),
        '*' => Http::response('<Error><Code>AccessDenied</Code></Error>', 403),
    ]);
    Setting::put('backup_target', 's3');
    Setting::put('backup_s3_bucket', 'cd-backups');
    Setting::put('backup_s3_region', 'eu-west-1');
    Setting::put('backup_s3_access_key', 'AKIAEXAMPLE');
    Setting::put('backup_s3_secret_key', Crypt::encryptString('s3cr3t'));
    $admin = backupAdmin();

    expect(Artisan::call('cortendesk:backup'))->toBe(1);

    $audit = \App\Models\ConsoleAudit::where('action', 'backup.create')->sole();
    expect($audit->user_id)->toBe($admin->id)
        ->and($audit->summary)->toContain('Off-site copy failed');

    $this->assertDatabaseHas('notification_deliveries', ['event' => 'backup.failed', 'status' => 'sent']);
    $this->assertDatabaseMissing('notification_deliveries', ['event' => 'backup.completed']);
});
