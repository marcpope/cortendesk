<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\ConsoleAudit;
use App\Models\Setting;
use App\Models\UserGroup;
use App\Services\MailSettings;
use App\Services\OidcService;
use App\Support\LoginEmailVerification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Livewire\Attributes\Url;
use Livewire\Component;

class SettingsPage extends Component
{
    use AuthorizesConsole;

    // Active settings tab — held server-side so it survives the Livewire morph
    // after a save (Bootstrap's JS tab state would reset on re-render).
    #[Url(except: 'server')]
    public string $tab = 'server';

    public string $idServer = '';

    public string $relayServer = '';

    public string $publicKey = '';

    public int $onlineWindow = 60;

    public string $rdgenUrl = '';

    /** Whether the sign-in page shows the published client-download icons. */
    public bool $downloadsOnLogin = true;

    public int $logRetentionDays = 365;

    public bool $requireDeviceApproval = false;

    public bool $twoFactorRequired = false;

    public bool $twoFactorRequiredAdmins = false;

    /** @var array<int, array{address: string, geo: string}> */
    public array $relayServers = [];

    // ---- Single sign-on (PLAN D3) ----------------------------------------

    public bool $oidcEnabled = false;

    public string $oidcDiscoveryUrl = '';

    public string $oidcPublicBaseUrl = '';

    public string $oidcClientId = '';

    /** Write-only. Left blank on load; a blank value on save keeps the stored secret. */
    public string $oidcClientSecret = '';

    /** Whether a secret is already stored, so the UI can say so without revealing it. */
    public bool $oidcClientSecretSet = false;

    public string $oidcScopes = '';

    public string $oidcButtonLabel = '';

    public string $oidcNewUserPolicy = 'deny';

    public bool $oidcDefaultAdmin = false;

    public int $oidcDefaultGroupId = 0;

    public string $oidcAllowedDomains = '';

    /** Opt-in strictness: refuse unless the provider asserts email_verified. */
    public bool $oidcRequireVerifiedEmail = false;

    public bool $oidcLogoutEnabled = false;

    public bool $oidcDisableLocalLogin = false;

    public string $oidcTestMessage = '';

    public bool $oidcTestOk = false;

    // ---- Outbound email / SMTP (PLAN D1) ---------------------------------

    public bool $smtpEnabled = false;

    public string $smtpHost = '';

    public int $smtpPort = 587;

    public string $smtpEncryption = 'starttls';

    public string $smtpUsername = '';

    /** Write-only. Left blank on load; a blank value on save keeps the stored password. */
    public string $smtpPassword = '';

    /** Whether a password is already stored, so the UI can say so without revealing it. */
    public bool $smtpPasswordSet = false;

    public string $smtpFromAddress = '';

    public string $smtpFromName = '';

    public string $smtpTestTo = '';

    public string $smtpTestMessage = '';

    public bool $smtpTestOk = false;

    /** Emailed 6-digit code on a browser the console has not seen before. */
    public bool $emailLoginVerification = false;

    public int $emailTrustedDeviceDays = 30;

    public bool $saved = false;

    public string $pruneResult = '';

    public function mount(): void
    {
        // "View" opens the screen read-only; every mutator below re-checks for
        // "Manage" (PLAN D4). Nothing loaded here is a decrypted secret — only
        // *Set booleans — so a view-only role cannot read the SSO or SMTP
        // credentials out of the component state. Keep it that way: any future
        // card that hydrates a plaintext secret into a public property would
        // leak it to a view-only role.
        $this->authorizeConsole('setting', 'r');

        $this->idServer = Setting::get('id_server', config('cortendesk.id_server')) ?? '';
        $this->relayServer = Setting::get('relay_server', config('cortendesk.relay_server')) ?? '';
        $this->publicKey = Setting::get('public_key', config('cortendesk.public_key')) ?? '';
        $this->onlineWindow = (int) (Setting::get('online_window', (string) config('cortendesk.online_window')) ?: 60);
        $this->rdgenUrl = Setting::get('rdgen_url', config('cortendesk.rdgen_url')) ?? '';
        $this->downloadsOnLogin = Setting::get('downloads_on_login', config('cortendesk.downloads_on_login') ? '1' : '0') === '1';
        $this->logRetentionDays = (int) (Setting::get('log_retention_days', (string) config('cortendesk.log_retention_days')) ?: 0);
        $this->requireDeviceApproval = (bool) Setting::get('require_device_approval', '0');
        $this->twoFactorRequired = Setting::get('two_factor_required', '0') === '1';
        $this->twoFactorRequiredAdmins = Setting::get('two_factor_required_admins', '0') === '1';

        $this->oidcEnabled = Setting::get('oidc_enabled', '0') === '1';
        $this->oidcDiscoveryUrl = Setting::get('oidc_discovery_url', '') ?? '';
        $this->oidcPublicBaseUrl = Setting::get('oidc_public_base_url', '') ?? '';
        $this->oidcClientId = Setting::get('oidc_client_id', '') ?? '';
        $this->oidcClientSecretSet = trim((string) Setting::get('oidc_client_secret', '')) !== '';
        $this->oidcScopes = Setting::get('oidc_scopes', 'openid email profile') ?? '';
        $this->oidcButtonLabel = Setting::get('oidc_button_label', '') ?? '';
        $this->oidcNewUserPolicy = Setting::get('oidc_new_user_policy', 'deny') ?? 'deny';
        $this->oidcDefaultAdmin = Setting::get('oidc_default_admin', '0') === '1';
        $this->oidcDefaultGroupId = (int) (Setting::get('oidc_default_group_id', '0') ?: 0);
        $this->oidcAllowedDomains = Setting::get('oidc_allowed_domains', '') ?? '';
        $this->oidcRequireVerifiedEmail = Setting::get('oidc_require_verified_email', '0') === '1';
        $this->oidcLogoutEnabled = Setting::get('oidc_logout_enabled', '0') === '1';
        $this->oidcDisableLocalLogin = Setting::get('oidc_disable_local_login', '0') === '1';

        $this->smtpEnabled = Setting::get('smtp_enabled', '0') === '1';
        $this->smtpHost = Setting::get('smtp_host', '') ?? '';
        $this->smtpPort = (int) (Setting::get('smtp_port', '587') ?: 587);
        $this->smtpEncryption = Setting::get('smtp_encryption', 'starttls') ?? 'starttls';
        $this->smtpUsername = Setting::get('smtp_username', '') ?? '';
        $this->smtpPasswordSet = trim((string) Setting::get('smtp_password', '')) !== '';
        $this->smtpFromAddress = Setting::get('smtp_from_address', '') ?? '';
        $this->smtpFromName = Setting::get('smtp_from_name', '') ?? '';
        $this->smtpTestTo = (string) (auth()->user()?->email ?: '');
        $this->emailLoginVerification = Setting::get('email_login_verification', '0') === '1';
        $this->emailTrustedDeviceDays = (int) (Setting::get('email_trusted_device_days', '30') ?: 30);

        $stored = json_decode(Setting::get('relay_servers', '') ?: '[]', true);
        $this->relayServers = is_array($stored) ? array_values(array_map(fn ($r) => [
            'address' => (string) ($r['address'] ?? ''),
            'geo' => (string) ($r['geo'] ?? ''),
        ], $stored)) : [];
    }

    /** Append a blank relay row for the operator to fill in. */
    public function addRelay(): void
    {
        $this->authorizeConsole('setting', 'rw');

        $this->relayServers[] = ['address' => '', 'geo' => ''];
    }

    /** Drop a relay row and re-index the list. */
    public function removeRelay(int $index): void
    {
        $this->authorizeConsole('setting', 'rw');

        unset($this->relayServers[$index]);
        $this->relayServers = array_values($this->relayServers);
    }

    /**
     * Which tab renders a validated field. One validate() spans every tab, so
     * a failure can belong to a pane the operator is not looking at — save()
     * jumps there, or the only visible symptom is a Save button that does
     * nothing (#18's reporter met this shape on the security tab).
     */
    private static function tabForField(string $field): ?string
    {
        $root = strtok($field, '.');

        return match (true) {
            str_starts_with($root, 'oidc') => 'sso',
            str_starts_with($root, 'smtp') => 'email',
            $root === 'logRetentionDays' => 'maintenance',
            in_array($root, ['twoFactorRequired', 'twoFactorRequiredAdmins', 'emailLoginVerification', 'emailTrustedDeviceDays'], true) => 'security',
            in_array($root, ['idServer', 'relayServer', 'publicKey', 'onlineWindow', 'rdgenUrl', 'downloadsOnLogin', 'relayServers', 'requireDeviceApproval'], true) => 'server',
            default => null,
        };
    }

    public function save(): void
    {
        $this->authorizeConsole('setting', 'rw');

        try {
            $this->validate([
                'idServer' => 'nullable|string|max:255',
                'relayServer' => 'nullable|string|max:255',
                'publicKey' => 'nullable|string|max:255',
                'onlineWindow' => 'required|integer|min:20|max:600',
                'rdgenUrl' => 'nullable|url|max:255',
                'logRetentionDays' => 'required|integer|min:0|max:3650',
                'relayServers' => 'array',
                'relayServers.*.address' => 'nullable|string|max:255',
                'relayServers.*.geo' => 'nullable|string|max:64',
                'oidcDiscoveryUrl' => 'nullable|url|max:255',
                'oidcPublicBaseUrl' => 'nullable|url|max:255',
                'oidcClientId' => 'nullable|string|max:255',
                'oidcClientSecret' => 'nullable|string|max:512',
                'oidcScopes' => 'nullable|string|max:255',
                'oidcButtonLabel' => 'nullable|string|max:64',
                'oidcNewUserPolicy' => 'required|in:deny,pending,active',
                'oidcDefaultGroupId' => 'nullable|integer|min:0',
                'oidcAllowedDomains' => 'nullable|string|max:512',
                'smtpHost' => 'nullable|string|max:255',
                'smtpPort' => 'required|integer|min:1|max:65535',
                'smtpEncryption' => 'required|in:starttls,ssl,none',
                'smtpUsername' => 'nullable|string|max:255',
                'smtpPassword' => 'nullable|string|max:512',
                'smtpFromAddress' => 'nullable|email|max:255',
                'smtpFromName' => 'nullable|string|max:64',
                'emailTrustedDeviceDays' => 'required|integer|min:1|max:365',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->tab = self::tabForField((string) array_key_first($e->errors())) ?? $this->tab;

            throw $e;
        }

        // Turning SSO on without the three fields needed to reach a provider
        // would hide the password form behind a door that doesn't open.
        if ($this->oidcEnabled) {
            $missingSecret = $this->oidcClientSecret === '' && ! $this->oidcClientSecretSet;

            if ($this->oidcDiscoveryUrl === '' || $this->oidcClientId === '' || $missingSecret) {
                $this->tab = 'sso';
                $this->addError('oidcDiscoveryUrl', 'Provider URL, client ID and client secret are all required to enable SSO.');

                return;
            }
        }

        // Switching email on without somewhere to send from would silently
        // swallow every invitation and sign-in code.
        if ($this->smtpEnabled && (trim($this->smtpHost) === '' || trim($this->smtpFromAddress) === '')) {
            $this->tab = 'email';
            $this->addError('smtpHost', 'Host and From address are both required to enable email.');

            return;
        }

        // Drop blank rows; persist the pool as JSON in the Setting model. The single
        // relay_server value stays as the fallback for empty lists (docs/relay-protocol.md).
        $relays = array_values(array_filter(array_map(fn ($r) => [
            'address' => trim((string) ($r['address'] ?? '')),
            'geo' => trim((string) ($r['geo'] ?? '')),
        ], $this->relayServers), fn ($r) => $r['address'] !== ''));

        $this->relayServers = $relays;
        Setting::put('relay_servers', json_encode($relays));

        Setting::put('id_server', trim($this->idServer));
        Setting::put('relay_server', trim($this->relayServer));
        // Pasting a key through an editor commonly drags in a trailing newline
        // or space. The comparison is exact, so an untrimmed key fails exactly
        // like a wrong one and there is nothing on screen to hint at why.
        Setting::put('public_key', trim($this->publicKey));
        Setting::put('online_window', (string) $this->onlineWindow);
        Setting::put('rdgen_url', rtrim($this->rdgenUrl, '/'));
        Setting::put('downloads_on_login', $this->downloadsOnLogin ? '1' : '0');
        Setting::put('log_retention_days', (string) $this->logRetentionDays);
        Setting::put('require_device_approval', $this->requireDeviceApproval ? '1' : '0');
        Setting::put('two_factor_required', $this->twoFactorRequired ? '1' : '0');
        Setting::put('two_factor_required_admins', $this->twoFactorRequiredAdmins ? '1' : '0');

        Setting::put('oidc_enabled', $this->oidcEnabled ? '1' : '0');
        Setting::put('oidc_discovery_url', rtrim(trim($this->oidcDiscoveryUrl), '/'));
        Setting::put('oidc_public_base_url', rtrim(trim($this->oidcPublicBaseUrl), '/'));
        Setting::put('oidc_client_id', trim($this->oidcClientId));
        Setting::put('oidc_scopes', trim($this->oidcScopes));
        Setting::put('oidc_button_label', trim($this->oidcButtonLabel));
        Setting::put('oidc_new_user_policy', $this->oidcNewUserPolicy);
        Setting::put('oidc_default_admin', $this->oidcDefaultAdmin ? '1' : '0');
        Setting::put('oidc_default_group_id', (string) $this->oidcDefaultGroupId);
        Setting::put('oidc_allowed_domains', trim($this->oidcAllowedDomains));
        Setting::put('oidc_require_verified_email', $this->oidcRequireVerifiedEmail ? '1' : '0');
        Setting::put('oidc_logout_enabled', $this->oidcLogoutEnabled ? '1' : '0');
        Setting::put('oidc_disable_local_login', $this->oidcDisableLocalLogin ? '1' : '0');

        // Blank means "leave the stored secret alone" — the field is never
        // populated with the real value, so a save from the UI must not wipe it.
        if ($this->oidcClientSecret !== '') {
            Setting::put('oidc_client_secret', Crypt::encryptString($this->oidcClientSecret));
            $this->oidcClientSecret = '';
            $this->oidcClientSecretSet = true;
        }

        Setting::put('smtp_enabled', $this->smtpEnabled ? '1' : '0');
        Setting::put('smtp_host', trim($this->smtpHost));
        Setting::put('smtp_port', (string) $this->smtpPort);
        Setting::put('smtp_encryption', $this->smtpEncryption);
        Setting::put('smtp_username', trim($this->smtpUsername));
        Setting::put('smtp_from_address', trim($this->smtpFromAddress));
        Setting::put('smtp_from_name', trim($this->smtpFromName));
        Setting::put('email_trusted_device_days', (string) $this->emailTrustedDeviceDays);

        // Blank means "leave the stored password alone" — same rule as the SSO
        // client secret above.
        if ($this->smtpPassword !== '') {
            Setting::put('smtp_password', Crypt::encryptString($this->smtpPassword));
            $this->smtpPassword = '';
            $this->smtpPasswordSet = true;
        }

        // Emailed sign-in codes can only be armed once email actually works —
        // otherwise the switch would demand a code nobody can ever receive.
        $canVerifyByEmail = app(MailSettings::class)->isEnabled();
        $this->emailLoginVerification = $this->emailLoginVerification && $canVerifyByEmail;
        Setting::put('email_login_verification', $this->emailLoginVerification ? '1' : '0');

        // Endpoints and keys may have moved with the provider URL.
        app(OidcService::class)->forgetCaches();

        ConsoleAudit::record('settings.update', 'Updated server settings', 'settings', null);

        $this->saved = true;
    }

    /** "Prune now": run retention immediately and surface the summary line. */
    public function pruneNow(): void
    {
        $this->authorizeConsole('setting', 'rw');

        Setting::put('log_retention_days', (string) $this->logRetentionDays);
        Artisan::call('cortendesk:prune-logs');
        $this->pruneResult = trim(Artisan::output());

        ConsoleAudit::record('logs.prune', 'Pruned logs older than '.$this->logRetentionDays.' days', 'logs', null);
    }

    /**
     * Probe the configured provider without saving, so an operator can prove
     * the URL and network path work before switching SSO on.
     */
    public function testOidc(): void
    {
        $this->authorizeConsole('setting', 'rw');

        $service = app(OidcService::class);

        if (! $service->isConfigured()) {
            $this->oidcTestOk = false;
            $this->oidcTestMessage = 'Save the provider URL, client ID and client secret first.';

            return;
        }

        $result = $service->test();
        $this->oidcTestOk = $result['ok'];
        $this->oidcTestMessage = $result['message'];
    }

    /**
     * Deliver a real message through the SAVED settings, so an operator sees
     * the relay's own error rather than a generic failure. Unsaved form state
     * is deliberately not used — same contract as Test Connection for SSO.
     */
    public function sendTestEmail(): void
    {
        $this->authorizeConsole('setting', 'rw');

        $mail = app(MailSettings::class);

        if (! $mail->isConfigured()) {
            $this->smtpTestOk = false;
            $this->smtpTestMessage = 'Save the SMTP settings first.';

            return;
        }

        $to = trim($this->smtpTestTo);

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->smtpTestOk = false;
            $this->smtpTestMessage = 'Enter a valid address to send the test to.';

            return;
        }

        $result = $mail->test($to);
        $this->smtpTestOk = $result['ok'];
        $this->smtpTestMessage = $result['message'];

        ConsoleAudit::record('settings.mail-test', 'Sent a test email to '.$to, 'settings', null);
    }

    public function render()
    {
        return view('livewire.settings-page', [
            'apiUrl' => rtrim(config('app.url'), '/'),
            'userGroups' => UserGroup::query()->orderBy('name')->get(['id', 'name']),
            'oidcCallbackUrl' => route('login.oidc.callback'),
            'mailEnabled' => app(MailSettings::class)->isEnabled(),
            'usersWithoutEmail' => LoginEmailVerification::usersWithoutEmail(),
        ]);
    }
}
