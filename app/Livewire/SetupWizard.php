<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\Setting;
use App\Models\User;
use Livewire\Component;

class SetupWizard extends Component
{
    use AuthorizesConsole;

    public bool $deviceConnected = false;

    public function mount(): void
    {
        $this->authorizeConsole('setting', 'r');
        $this->deviceConnected = Device::query()->whereNotNull('last_online_at')->exists();
    }

    public static function shouldPrompt(?User $user): bool
    {
        return $user !== null
            && $user->consoleAllows('setting', 'rw')
            && $user->setup_wizard_dismissed_at === null
            && $user->setup_wizard_completed_at === null
            && ! Device::query()->exists();
    }

    public function refreshDeviceStatus(): void
    {
        $this->deviceConnected = Device::query()->whereNotNull('last_online_at')->exists();
    }

    public function dismiss(): void
    {
        $this->authorizeConsole('setting', 'rw');
        auth()->user()->forceFill(['setup_wizard_dismissed_at' => now()])->save();
        ConsoleAudit::record('setup.dismiss', 'Dismissed the first-run setup guide', 'settings', null);

        $this->redirectRoute('overview');
    }

    public function complete(): void
    {
        $this->authorizeConsole('setting', 'rw');
        $this->refreshDeviceStatus();

        if (! $this->deviceConnected) {
            $this->addError('device', 'Connect a RustDesk device before completing setup.');

            return;
        }

        auth()->user()->forceFill(['setup_wizard_completed_at' => now()])->save();
        ConsoleAudit::record('setup.complete', 'Completed the first-run setup guide', 'settings', null);

        $this->redirectRoute('devices');
    }

    public function render()
    {
        return view('livewire.setup-wizard', [
            'idServer' => Setting::server('id_server'),
            'relayServer' => Setting::server('relay_server'),
            'publicKey' => Setting::server('public_key'),
            'apiUrl' => rtrim((string) config('app.url'), '/'),
            'approvalEnabled' => Setting::get('require_device_approval', '0') === '1',
            'twoFactorRequired' => Setting::get('two_factor_required', '0') === '1'
                || Setting::get('two_factor_required_admins', '0') === '1',
        ]);
    }
}
