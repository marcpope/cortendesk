<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceShare;
use App\Models\Strategy;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Share screen" on the device page: list, create and end share links.
 * Seeing the card needs device read access; creating and ending need write
 * access. The device must be visible to the user either way.
 */
class DeviceShares extends Component
{
    use AuthorizesConsole;

    public int $deviceId;

    public bool $creating = false;

    public string $label = '';

    public string $duration = '1h';

    public int $maxViewers = 1;

    /** The link just created, shown once. Never stored anywhere else. */
    public ?string $newLink = null;

    public function mount(int $deviceId): void
    {
        $this->authorizeConsole('device', 'r');
        $this->deviceId = $deviceId;
        $this->device();
        $this->resetForm();
        if (request()->boolean('share') && $this->consoleAllows('device', 'rw')) {
            $this->creating = true;
        }
    }

    private function device(): Device
    {
        return Device::query()->visibleTo(auth()->user())->findOrFail($this->deviceId);
    }

    private function resetForm(): void
    {
        $this->label = '';
        $this->duration = '1h';
        $this->maxViewers = 1;
    }

    /** The device overview's Share screen tile. */
    #[On('device-share-start')]
    public function startCreate(): void
    {
        $this->authorizeConsole('device', 'rw');
        $this->resetForm();
        $this->newLink = null;
        $this->creating = true;
    }

    public function cancelCreate(): void
    {
        $this->resetForm();
        $this->creating = false;
    }

    public function create(): void
    {
        $this->authorizeConsole('device', 'rw');
        $this->validate([
            'label' => ['required', 'string', 'max:100'],
            'duration' => ['required', Rule::in(array_keys(DeviceShare::DURATIONS))],
            'maxViewers' => ['required', 'integer', 'min:1', 'max:'.DeviceShare::MAX_VIEWERS],
        ]);

        $device = $this->device();
        $token = DeviceShare::newToken();
        $share = DeviceShare::create([
            'device_id' => $device->id,
            'user_id' => auth()->id(),
            'label' => trim($this->label),
            'token_hash' => $token['hash'],
            'max_viewers' => $this->maxViewers,
            'expires_at' => now()->addMinutes(DeviceShare::DURATIONS[$this->duration]),
        ]);

        ConsoleAudit::record('device.share.create', 'Created share link "'.$share->label.'" for device '.$device->rustdesk_id.' (up to '.$this->maxViewers.' at a time, '.$this->duration.')', 'device', $device->rustdesk_id);

        $this->newLink = route('share.show').'#'.$token['token'];
        $this->resetForm();
        $this->creating = false;
    }

    public function dismissLink(): void
    {
        $this->newLink = null;
    }

    public function revoke(int $shareId): void
    {
        $this->authorizeConsole('device', 'rw');
        $device = $this->device();
        $share = DeviceShare::query()->where('device_id', $device->id)->findOrFail($shareId);
        if ($share->revoked_at === null) {
            $share->forceFill(['revoked_at' => now()])->save();
            // Open share pages learn it on their next ping and disconnect.
            $share->sessions()->whereNull('ended_at')->update(['ended_at' => now()]);
            ConsoleAudit::record('device.share.revoke', 'Ended share link "'.$share->label.'" for device '.$device->rustdesk_id, 'device', $device->rustdesk_id);
        }
    }

    /**
     * A console strategy has set this device to accept password logins only,
     * so a share viewer (who has no password) can never get in. What the
     * device last confirmed wins over what it was told. Without a strategy the
     * setting lives on the device and the console cannot see it.
     */
    private function passwordOnly(Device $device): bool
    {
        $acked = Strategy::stringMap($device->strategy_acked_options);
        $mode = $acked['approve-mode'] ?? ($device->resolvedStrategy?->configOptions()['approve-mode'] ?? null);

        return $mode === 'password';
    }

    public function render()
    {
        $device = $this->device();
        $shares = DeviceShare::query()
            ->where('device_id', $device->id)
            ->with('user')
            ->withCount(['sessions as live_viewers' => fn ($q) => $q->live()])
            ->orderByRaw('CASE WHEN revoked_at IS NULL AND expires_at > ? THEN 0 ELSE 1 END', [now()])
            ->latest()
            ->limit(20)
            ->get();

        return view('livewire.device-shares', [
            'shares' => $shares,
            'passwordOnly' => $this->passwordOnly($device),
            'canManage' => $this->consoleAllows('device', 'rw') && ! $device->trashed(),
        ]);
    }
}
