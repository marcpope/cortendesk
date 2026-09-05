<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\GroupAccess;
use App\Models\UserGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

class GroupList extends Component
{
    use AuthorizesConsole;

    #[Url(except: 'devices')]
    public string $tab = 'devices';

    // Modal state — $editing null means "create".
    public bool $showModal = false;

    public string $modalType = 'devices'; // devices|users

    public ?int $editing = null;

    public string $name = '';

    public string $note = '';

    /** @var array<int,int> device_group ids granted to the user group being edited */
    public array $device_group_ids = [];

    /**
     * @var array<int,int> user-group ids "accessed from" the group being edited
     *                     (whose members may see this group). PLAN B4.
     */
    public array $accessor_group_ids = [];

    /**
     * Groups needs "View" to open (PLAN D4). The `group` area covers BOTH
     * device groups and user groups, exactly as the API-token matrix does.
     */
    public function mount(): void
    {
        $this->authorizeConsole('group', 'r');
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['devices', 'users'], true)) {
            $this->tab = $tab;
        }
    }

    public function create(string $type): void
    {
        $this->authorizeConsole('group', 'rw');

        $this->resetForm();
        $this->modalType = $this->validType($type);
        $this->showModal = true;
    }

    public function edit(string $type, int $id): void
    {
        $this->authorizeConsole('group', 'rw');

        $this->resetForm();
        $this->modalType = $this->validType($type);
        $group = $this->model()::findOrFail($id);

        $this->editing = $group->id;
        $this->name = $group->name;
        $this->note = $group->note ?? '';
        if ($group instanceof UserGroup) {
            $this->device_group_ids = $group->deviceGroups()->pluck('device_groups.id')->all();
        }
        // "Accessed from": which user groups may see this group (B4). Applies to
        // both device groups (folder access) and user groups (group-mate access).
        $this->accessor_group_ids = $group->accessorUserGroupIds();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorizeConsole('group', 'rw');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'device_group_ids' => ['array'],
            'device_group_ids.*' => [Rule::exists('device_groups', 'id')],
            'accessor_group_ids' => ['array'],
            'accessor_group_ids.*' => [Rule::exists('user_groups', 'id')],
        ]);

        $deviceGroupIds = array_map('intval', $validated['device_group_ids'] ?? []);
        $accessorGroupIds = array_map('intval', $validated['accessor_group_ids'] ?? []);
        unset($validated['device_group_ids'], $validated['accessor_group_ids']);

        $validated['note'] = ($validated['note'] ?? '') !== '' ? $validated['note'] : null;

        if ($this->editing) {
            $group = $this->model()::findOrFail($this->editing);
            $group->update($validated);
        } else {
            $group = $this->model()::create($validated);
        }

        // ---- Escalation guards for a delegated group-manager (PLAN D4) -------
        // `group: rw` is a console verb, not row scope. Both syncs below hand
        // out DEVICE visibility (User::accessibleDeviceGroupIds unions
        // device_group_user_group and the device-group side of group_accesses),
        // so a role-holder who is a member of the group being edited could
        // otherwise grant themselves the whole fleet. They may only ever pass on
        // folders they can already see, and grants outside their reach are left
        // exactly as they were — the editor never showed them.
        $actor = auth()->user();
        $isSuperAdmin = (bool) $actor?->is_admin;

        // Folder access granted to every member of this user group.
        if ($group instanceof UserGroup) {
            if (! $isSuperAdmin) {
                $existing = $group->deviceGroups()->pluck('device_groups.id')
                    ->map(fn ($id) => (int) $id)->all();
                $deviceGroupIds = array_values(array_unique(array_merge(
                    $this->grantableDeviceGroupIds($deviceGroupIds),
                    array_diff($existing, $this->grantableDeviceGroupIds($existing)),
                )));
            }

            $group->deviceGroups()->sync($deviceGroupIds);
        }

        // "Accessed from": user groups whose members may see this group (B4).
        // A group can never be accessed from itself.
        //
        // On a DEVICE group this is the same grant from the other side, so a
        // delegate may only edit it for a folder they can see themselves.
        if ($group instanceof DeviceGroup && ! $isSuperAdmin
            && $this->grantableDeviceGroupIds([$group->id]) === []) {
            $accessorGroupIds = $group->accessorUserGroupIds();
        }

        $group->syncAccessorUserGroups(
            $group instanceof UserGroup
                ? array_values(array_diff($accessorGroupIds, [$group->id]))
                : $accessorGroupIds
        );

        $kind = $group instanceof UserGroup ? 'user group' : 'device group';
        ConsoleAudit::record(
            $this->editing ? 'group.update' : 'group.create',
            ($this->editing ? 'Updated' : 'Created').' '.$kind.' '.$group->name,
            'group',
            $group->name,
        );

        $this->closeModal();
    }

    public function deleteGroup(string $type, int $id): void
    {
        $this->authorizeConsole('group', 'rw');

        $type = $this->validType($type);

        if ($type === 'devices') {
            $group = DeviceGroup::findOrFail($id);
            $name = $group->name;
            DB::transaction(function () use ($group, $id): void {
                Device::bulkUpdateStrategyContext(
                    Device::query()->where('device_group_id', $id),
                    ['device_group_id' => null],
                    auth()->user()->consoleAllows('strategy', 'rw'),
                );
                GroupAccess::purgeFor(GroupAccess::TARGET_DEVICE_GROUP, $group->id);
                $group->delete();
            });
        } else {
            $group = UserGroup::findOrFail($id);
            $name = $group->name;
            $group->users()->detach();
            $group->deviceGroups()->detach();
            // Drop group_accesses where this user group is accessor or target.
            GroupAccess::purgeFor(GroupAccess::ACCESSOR_USER_GROUP, $group->id);
            $group->delete();
        }

        $kind = $type === 'devices' ? 'device group' : 'user group';
        ConsoleAudit::record('group.delete', 'Deleted '.$kind.' '.$name, 'group', $name);
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'name', 'note', 'device_group_ids', 'accessor_group_ids');
        $this->resetValidation();
    }

    private function validType(string $type): string
    {
        return $type === 'users' ? 'users' : 'devices';
    }

    /** @return class-string<DeviceGroup|UserGroup> */
    private function model(): string
    {
        return $this->modalType === 'users' ? UserGroup::class : DeviceGroup::class;
    }

    public function render()
    {
        return view('livewire.group-list', [
            'deviceGroups' => DeviceGroup::withCount('devices')->orderBy('name')->get(),
            'userGroups' => UserGroup::withCount('users')->with('deviceGroups')->orderBy('name')->get(),
            // The folder picker inside the editor offers only what save() would
            // accept from this actor; the lists above are the screen itself and
            // stay whole.
            'grantableDeviceGroups' => $this->grantableDeviceGroups(),
        ]);
    }
}
