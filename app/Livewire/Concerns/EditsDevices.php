<?php

namespace App\Livewire\Concerns;

use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\User;

/**
 * The Add / Edit device modal, shared by the device list and the device
 * detail page (issue #54) so both run the same validation, scoping and audit.
 * The markup is resources/views/livewire/partials/device-edit-modal; pass it
 * editorViewData().
 */
trait EditsDevices
{
    /** Device id being edited, 0 = creating, null = modal closed. */
    public ?int $editingId = null;

    public string $formRustdeskId = '';

    public string $formAlias = '';

    public string $formNote = '';

    public int $formGroupId = 0;

    public int $formUserId = 0;

    /** Device-level strategy assignment (PLAN C4). 0 = none, inherit. */
    public int $formStrategyId = 0;

    /** Off = incoming only (issue #82). */
    public bool $formCanInitiate = true;

    /** Load a device the current user is allowed to see, or fail. */
    private function scopedDevice(int $id): Device
    {
        return Device::withTrashed()->visibleTo(auth()->user())->findOrFail($id);
    }

    public function edit(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedDevice($id);
        $this->resetValidation();
        $this->editingId = $device->id;
        $this->formRustdeskId = $device->rustdesk_id;
        $this->formAlias = (string) $device->alias;
        $this->formNote = (string) $device->note;
        $this->formGroupId = (int) $device->device_group_id;
        $this->formUserId = (int) $device->user_id;
        $this->formCanInitiate = ! $device->isIncomingOnly();
        // Only admins may see or set strategies; for everyone else the field
        // stays 0 and save() ignores it, so it cannot be posted from a form
        // that never rendered it.
        $this->formStrategyId = auth()->user()?->is_admin
            ? (int) $device->assignedStrategyId()
            : 0;
    }

    public function save(): void
    {
        $this->authorizeConsole('device', 'rw');

        $data = $this->validate([
            'formRustdeskId' => 'required|string|max:100',
            'formAlias' => 'nullable|string|max:255',
            'formNote' => 'nullable|string|max:500',
            'formGroupId' => 'integer',
            'formUserId' => 'integer',
            'formStrategyId' => 'integer',
            'formCanInitiate' => 'boolean',
        ]);

        $attributes = [
            'alias' => $data['formAlias'] ?: null,
            'note' => $data['formNote'] ?: null,
            'device_group_id' => $data['formGroupId'] ?: null,
            'user_id' => $data['formUserId'] ?: null,
            'can_initiate' => (bool) $data['formCanInitiate'],
        ];

        if ($this->editingId === 0) {
            $this->validate(['formRustdeskId' => 'unique:devices,rustdesk_id']);
            Device::create($attributes + [
                'rustdesk_id' => $data['formRustdeskId'],
                'uuid' => '',
            ]);
            ConsoleAudit::record('device.create', 'Created device '.$data['formRustdeskId'], 'device', $data['formRustdeskId']);
        } else {
            $device = $this->scopedDevice((int) $this->editingId);
            $device->update($attributes);
            ConsoleAudit::record('device.update', 'Updated device '.$device->rustdesk_id, 'device', $device->rustdesk_id);
            if ($device->wasChanged('can_initiate')) {
                ConsoleAudit::record(
                    'device.initiate',
                    'Device '.$device->rustdesk_id.' set '.($device->can_initiate ? 'allowed to start sessions' : 'incoming only'),
                    'device',
                    $device->rustdesk_id,
                );
            }

            $this->saveStrategyAssignment($device, (int) $data['formStrategyId']);
        }

        $this->editingId = null;
    }

    /**
     * Device-level strategy assignment from the editor (PLAN C4). Admin-only,
     * and a no-op unless it actually changes: assignTo() recomputes the cached
     * resolution and an unconditional call would audit "changed" for every save.
     */
    private function saveStrategyAssignment(Device $device, int $strategyId): void
    {
        if (! auth()->user()?->is_admin) {
            return;
        }

        $current = (int) $device->assignedStrategyId();
        if ($current === $strategyId) {
            return;
        }

        if ($strategyId !== 0 && ! Strategy::whereKey($strategyId)->exists()) {
            return; // stale option in a form left open while the strategy was deleted
        }

        Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategyId ?: null);

        $name = $strategyId === 0
            ? 'none (inherits)'
            : (string) Strategy::whereKey($strategyId)->value('name');

        ConsoleAudit::record(
            'strategy.assign',
            'Device '.$device->rustdesk_id.' strategy set to '.$name,
            'device',
            $device->rustdesk_id,
        );
    }

    public function closeModal(): void
    {
        $this->editingId = null;
    }

    /** Device groups this actor may see and therefore may choose. */
    private function accessibleDeviceGroups()
    {
        $user = auth()->user();

        return DeviceGroup::orderBy('name')
            ->when(! $user->seesAllDevices(), fn ($q) => $q->whereIn('id', $user->accessibleDeviceGroupIds() ?: [0]))
            ->get();
    }

    /**
     * What the modal partial needs. Empty collections while it is closed, so
     * a page that never opens the editor pays nothing for it.
     *
     * @return array<string, mixed>
     */
    protected function editorViewData(): array
    {
        $open = $this->editingId !== null;

        return [
            'editorGroups' => $open ? $this->accessibleDeviceGroups() : collect(),
            'editorUsers' => $open ? User::orderBy('username')->get(['id', 'username']) : collect(),
            'strategies' => $this->editorStrategies(),
            'strategyExplain' => $this->editorStrategyExplain(),
        ];
    }

    /** Strategies offered by the assignment select; admin editing an existing device only. */
    private function editorStrategies()
    {
        if (! auth()->user()?->is_admin || ! $this->editingId) {
            return collect();
        }

        return Strategy::orderBy('name')->get(['id', 'name', 'enabled', 'is_default']);
    }

    /** "Effective strategy" inspector data for the editor (PLAN C4). */
    private function editorStrategyExplain(): ?array
    {
        if (! auth()->user()?->is_admin || ! $this->editingId) {
            return null;
        }

        $device = Device::withTrashed()->visibleTo(auth()->user())->find($this->editingId);

        return $device === null ? null : Strategy::explainFor($device);
    }
}
