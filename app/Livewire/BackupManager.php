<?php

namespace App\Livewire;

use App\Support\Label;
use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\ConsoleAudit;
use App\Models\Setting;
use App\Services\Backups\BackupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Settings -> Backups. Schedule and retention, the off-site copy target, the
 * local archives (download, restore, delete), and restore from an upload.
 *
 * Lives in its own component rather than SettingsPage because it carries a
 * file upload and long-running actions, and because its save is independent
 * of the one giant validate() the other tabs share.
 *
 * mount() checks View; every mutator re-checks Manage, because /livewire/update
 * is reachable directly (see the AuthorizesConsole docblock).
 */
class BackupManager extends Component
{
    use AuthorizesConsole;
    use WithFileUploads;

    public bool $enabled = true;

    public int $keepDaily = 7;

    public int $keepWeekly = 8;

    public int $keepMonthly = 6;

    public string $target = 'none';

    // ---- FTP ---------------------------------------------------------------

    public string $ftpHost = '';

    public int $ftpPort = 21;

    public string $ftpUsername = '';

    /** Write-only. Blank on load; blank on save keeps the stored password. */
    public string $ftpPassword = '';

    public bool $ftpPasswordSet = false;

    public string $ftpPath = '';

    public bool $ftpTls = false;

    // ---- S3 ----------------------------------------------------------------

    public string $s3Endpoint = '';

    public string $s3Region = '';

    public string $s3Bucket = '';

    public string $s3Prefix = '';

    public string $s3AccessKey = '';

    /** Write-only, same rule as the FTP password. */
    public string $s3SecretKey = '';

    public bool $s3SecretKeySet = false;

    public bool $s3PathStyle = false;

    // ---- actions -----------------------------------------------------------

    /** Archive picked for "Restore from file". Untyped: see ClientDownloadManager. */
    public $upload = null;

    /** @var array{tone: string, text: string}|null */
    public ?array $notice = null;

    /** @var array{tone: string, text: string}|null shown next to the Test connection button */
    public ?array $testResult = null;

    /** Keys the result element, so each test renders a fresh one that scrolls itself into view. */
    public int $testRuns = 0;

    public bool $saved = false;

    public function mount(): void
    {
        $this->authorizeConsole('setting', 'r');

        $settings = BackupService::settings();
        $this->enabled = $settings['enabled'];
        $this->keepDaily = $settings['keep']['daily'];
        $this->keepWeekly = $settings['keep']['weekly'];
        $this->keepMonthly = $settings['keep']['monthly'];
        $this->target = $settings['target'];

        $this->ftpHost = (string) Setting::get('backup_ftp_host', '');
        $this->ftpPort = (int) Setting::get('backup_ftp_port', '21') ?: 21;
        $this->ftpUsername = (string) Setting::get('backup_ftp_username', '');
        $this->ftpPasswordSet = (string) Setting::get('backup_ftp_password', '') !== '';
        $this->ftpPath = (string) Setting::get('backup_ftp_path', '');
        $this->ftpTls = Setting::get('backup_ftp_tls', '0') === '1';

        $this->s3Endpoint = (string) Setting::get('backup_s3_endpoint', '');
        $this->s3Region = (string) Setting::get('backup_s3_region', '');
        $this->s3Bucket = (string) Setting::get('backup_s3_bucket', '');
        $this->s3Prefix = (string) Setting::get('backup_s3_prefix', '');
        $this->s3AccessKey = (string) Setting::get('backup_s3_access_key', '');
        $this->s3SecretKeySet = (string) Setting::get('backup_s3_secret_key', '') !== '';
        $this->s3PathStyle = Setting::get('backup_s3_path_style', '0') === '1';
    }

    public function save(): void
    {
        $this->authorizeConsole('setting', 'rw');
        $this->saved = false;
        $this->notice = null;
        $this->testResult = null;

        $this->validate([
            'keepDaily' => 'required|integer|min:0|max:'.BackupService::MAX_KEEP,
            'keepWeekly' => 'required|integer|min:0|max:'.BackupService::MAX_KEEP,
            'keepMonthly' => 'required|integer|min:0|max:'.BackupService::MAX_KEEP,
            'target' => 'required|in:'.implode(',', array_keys(BackupService::TARGETS)),
            'ftpHost' => 'nullable|string|max:255',
            'ftpPort' => 'required|integer|min:1|max:65535',
            'ftpUsername' => 'nullable|string|max:255',
            'ftpPassword' => 'nullable|string|max:512',
            'ftpPath' => 'nullable|string|max:512',
            's3Endpoint' => 'nullable|url:http,https|max:255',
            's3Region' => 'nullable|string|max:64',
            's3Bucket' => 'nullable|string|max:255',
            's3Prefix' => 'nullable|string|max:255',
            's3AccessKey' => 'nullable|string|max:255',
            's3SecretKey' => 'nullable|string|max:512',
        ]);

        if ($this->target === 'ftp' && trim($this->ftpHost) === '') {
            $this->addError('ftpHost', __('messages.ftp_host_needed'));

            return;
        }
        if ($this->target === 's3' && trim($this->s3Bucket) === '') {
            $this->addError('s3Bucket', __('messages.s3_bucket_needed'));

            return;
        }

        Setting::put('backup_enabled', $this->enabled ? '1' : '0');
        Setting::put('backup_keep_daily', (string) $this->keepDaily);
        Setting::put('backup_keep_weekly', (string) $this->keepWeekly);
        Setting::put('backup_keep_monthly', (string) $this->keepMonthly);
        Setting::put('backup_target', $this->target);

        Setting::put('backup_ftp_host', trim($this->ftpHost));
        Setting::put('backup_ftp_port', (string) $this->ftpPort);
        Setting::put('backup_ftp_username', trim($this->ftpUsername));
        Setting::put('backup_ftp_path', trim($this->ftpPath));
        Setting::put('backup_ftp_tls', $this->ftpTls ? '1' : '0');
        if ($this->ftpPassword !== '') {
            Setting::put('backup_ftp_password', Crypt::encryptString($this->ftpPassword));
            $this->ftpPassword = '';
            $this->ftpPasswordSet = true;
        }

        Setting::put('backup_s3_endpoint', trim($this->s3Endpoint));
        Setting::put('backup_s3_region', trim($this->s3Region));
        Setting::put('backup_s3_bucket', trim($this->s3Bucket));
        Setting::put('backup_s3_prefix', trim($this->s3Prefix));
        Setting::put('backup_s3_access_key', trim($this->s3AccessKey));
        Setting::put('backup_s3_path_style', $this->s3PathStyle ? '1' : '0');
        if ($this->s3SecretKey !== '') {
            Setting::put('backup_s3_secret_key', Crypt::encryptString($this->s3SecretKey));
            $this->s3SecretKey = '';
            $this->s3SecretKeySet = true;
        }

        ConsoleAudit::record('backup.settings', 'Updated backup settings ('.($this->enabled ? 'daily' : 'off').", keep {$this->keepDaily}/{$this->keepWeekly}/{$this->keepMonthly}, copy: {$this->target})", 'settings', null);

        $this->saved = true;
    }

    /**
     * Prove the saved off-site target works. Saves first so what is tested is
     * what the scheduler will use. The result sits next to the button, which
     * is far below the tab's notice area.
     */
    public function testTarget(): void
    {
        $this->save();
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $this->saved = false;
        $this->testRuns++;

        $target = BackupService::target();
        if ($target === null) {
            $this->testResult = ['tone' => 'info', 'text' => __('messages.saved_no_offsite')];

            return;
        }

        try {
            $this->testResult = ['tone' => 'success', 'text' => __('messages.saved_with_test', ['result' => $target->test()])];
        } catch (Throwable $e) {
            $this->testResult = ['tone' => 'danger', 'text' => __('messages.saved_test_failed', ['error' => $e->getMessage()])];
        }
    }

    public function backupNow(): void
    {
        $this->authorizeConsole('setting', 'rw');
        $this->saved = false;

        $code = Artisan::call('cortendesk:backup');
        $output = trim(Artisan::output());

        // The command writes the console log entry, with the outcome.
        $this->notice = ['tone' => $code === 0 ? 'success' : 'danger', 'text' => $output];
    }

    public function delete(string $name): void
    {
        $this->authorizeConsole('setting', 'rw');
        $this->saved = false;

        try {
            app(BackupService::class)->delete($name);
            ConsoleAudit::record('backup.delete', 'Deleted backup '.$name, 'backup', $name);
        } catch (Throwable $e) {
            $this->notice = ['tone' => 'danger', 'text' => $e->getMessage()];

            return;
        }

        // The off-site copy mirrors this list; take the file down there too.
        // If that fails the next run's sync removes it.
        $target = BackupService::target();
        if ($target === null) {
            $this->notice = ['tone' => 'info', 'text' => __('messages.backup_deleted', ['name' => $name])];

            return;
        }
        try {
            if (array_key_exists($name, $target->list())) {
                $target->delete($name);
            }
            $this->notice = ['tone' => 'info', 'text' => __('messages.backup_deleted_both', ['name' => $name, 'target' => $target->label()])];
        } catch (Throwable $e) {
            $this->notice = ['tone' => 'warning', 'text' => __('messages.backup_deleted_copy_left', ['name' => $name, 'target' => $target->label(), 'error' => $e->getMessage()])];
        }
    }

    public function restore(string $name): void
    {
        $this->authorizeConsole('setting', 'rw');
        $this->saved = false;

        $backups = app(BackupService::class);
        $path = $backups->path($name);
        if ($path === null) {
            $this->notice = ['tone' => 'danger', 'text' => __('messages.backup_not_found', ['name' => $name])];

            return;
        }

        $this->runRestore($backups, $path, $name);
    }

    public function restoreUpload(): void
    {
        $this->authorizeConsole('setting', 'rw');
        $this->saved = false;

        $this->validate(['upload' => ['required', 'file']], [
            'upload.required' => __('messages.pick_backup_archive'),
        ]);

        $backups = app(BackupService::class);

        try {
            $name = $backups->import($this->upload->getRealPath(), $this->upload->getClientOriginalName());
        } catch (Throwable $e) {
            $this->notice = ['tone' => 'danger', 'text' => $e->getMessage()];

            return;
        } finally {
            $this->upload = null;
        }

        $this->runRestore($backups, $backups->directory().'/'.$name, $name);
    }

    private function runRestore(BackupService $backups, string $path, string $name): void
    {
        try {
            $result = $backups->restore($path);
        } catch (Throwable $e) {
            ConsoleAudit::record('backup.restore', 'Restore of '.$name.' failed: '.$e->getMessage(), 'backup', $name);
            $this->notice = ['tone' => 'danger', 'text' => __('messages.restore_failed', ['error' => $e->getMessage()])];

            return;
        }

        ConsoleAudit::record('backup.restore', 'Restored backup '.$name, 'backup', $name);

        $text = __('messages.restored', ['name' => $name, 'rows' => $result['rows'], 'tables' => $result['tables']]);
        if ($result['rekeyed']) {
            $text .= ' '.__('messages.restore_rekeyed');
        }
        foreach ($result['dropped_columns'] as $table => $columns) {
            $text .= ' '.__('messages.restore_ignored', ['table' => $table, 'columns' => implode(', ', $columns)]);
        }
        if ($result['restart_needed']) {
            $text .= ' '.__('messages.restore_server_files', ['files' => implode(', ', $result['files'])]);
        }
        $text .= ' '.__('messages.restore_reload');

        $this->notice = ['tone' => 'success', 'text' => $text];

        // The restored rows are the truth now, including these settings.
        $this->mount();
    }

    public function render()
    {
        $canManage = $this->consoleAllows('setting', 'rw');

        $list = [];
        $directoryError = null;
        try {
            $list = app(BackupService::class)->list();
        } catch (Throwable $e) {
            $directoryError = $e->getMessage();
        }

        $last = null;
        $raw = Setting::get('backup_last_result');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $last = $decoded;
                try {
                    $last['at'] = Carbon::parse((string) ($decoded['at'] ?? ''));
                } catch (Throwable) {
                    $last['at'] = null;
                }
            }
        }

        return view('livewire.backup-manager', [
            'canManage' => $canManage,
            'backups' => $list,
            'directoryError' => $directoryError,
            'directory' => (string) config('cortendesk.backups_path'),
            'lastResult' => $last,
            'targets' => collect(BackupService::TARGETS)->map(fn ($label, $code) => Label::get('backup_target', $code, $label))->all(),
            'defaultKeep' => BackupService::DEFAULT_KEEP,
            'includesServerFiles' => (string) config('cortendesk.rustdesk_dir') !== '' && is_dir((string) config('cortendesk.rustdesk_dir')),
        ]);
    }
}
