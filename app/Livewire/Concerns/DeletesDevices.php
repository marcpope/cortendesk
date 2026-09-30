<?php

namespace App\Livewire\Concerns;

use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Services\DeviceAddressBooks;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Delete-to-recycle-bin with a confirm step that offers to remove the devices
 * from address books too (issue #85). Shared by the device list (row and bulk)
 * and the device detail page so all three behave and audit the same way.
 *
 * The confirm modal is resources/views/livewire/partials/device-delete-modal.
 * Callers render it with deleteConfirmState().
 */
trait DeletesDevices
{
    /** Device ids awaiting confirmation; null = modal closed. */
    public ?array $deleteIds = null;

    /** "Also remove from address books". Off unless the confirm step sets it. */
    public bool $deleteFromBooks = false;

    /** Open the confirm step for these device ids. */
    protected function openDeleteConfirm(array $ids): void
    {
        $this->authorizeConsole('device', 'rw');

        $devices = $this->deletableDevices($ids);
        if ($devices->isEmpty()) {
            return;
        }

        $this->deleteIds = $devices->pluck('id')->all();
        $this->deleteFromBooks = DeviceAddressBooks::entryCount($devices->pluck('rustdesk_id')->all()) > 0;
    }

    public function cancelDelete(): void
    {
        $this->deleteIds = null;
        $this->deleteFromBooks = false;
    }

    /**
     * Device count and address-book entry count for the modal, or null when
     * it is closed.
     *
     * @return array{devices: Collection<int, Device>, entries: int}|null
     */
    protected function deleteConfirmState(): ?array
    {
        if ($this->deleteIds === null) {
            return null;
        }

        $devices = $this->deletableDevices($this->deleteIds);

        return [
            'devices' => $devices,
            'entries' => DeviceAddressBooks::entryCount($devices->pluck('rustdesk_id')->all()),
        ];
    }

    /**
     * Soft-delete the confirmed devices and, when asked, drop their entries
     * from every book the actor may write. Returns the outcome line.
     */
    protected function deleteConfirmed(): string
    {
        $this->authorizeConsole('device', 'rw');

        $devices = $this->deletableDevices($this->deleteIds ?? []);
        $fromBooks = $this->deleteFromBooks;
        $this->cancelDelete();

        foreach ($devices as $device) {
            $device->delete(); // soft delete: recycle bin
            ConsoleAudit::record('device.delete', 'Deleted device '.$device->rustdesk_id, 'device', $device->rustdesk_id);
        }

        $count = $devices->count();
        $message = $count === 1
            ? 'Device '.$devices->first()->rustdesk_id.' moved to the recycle bin.'
            : $count.' '.Str::plural('device', $count).' moved to the recycle bin.';

        if ($fromBooks && $count > 0) {
            $result = DeviceAddressBooks::remove($devices->pluck('rustdesk_id')->all(), auth()->user());
            $message .= ' '.DeviceAddressBooks::summary($result);
        }

        return $message;
    }

    /**
     * Re-scoped on every call: ids arrive from the browser, so anything the
     * actor cannot see, or that is already in the recycle bin, drops out.
     *
     * @return Collection<int, Device>
     */
    private function deletableDevices(array $ids): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return collect();
        }

        return Device::query()
            ->visibleTo(auth()->user())
            ->whereIn('id', $ids)
            ->orderBy('rustdesk_id')
            ->get();
    }
}
