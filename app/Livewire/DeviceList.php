<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\AddressBook;
use App\Models\AddressBookRule;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class DeviceList extends Component
{
    use AuthorizesConsole, WithPagination;

    protected string $paginationTheme = 'bootstrap';

    /**
     * Optional table columns (issue #16), in render order. ID, Status and
     * Action are not here — they are the row's identity and controls, and a
     * table without them is not a device list. 'owner' is admin-only.
     */
    public const COLUMNS = [
        'device' => 'Device',
        'alias' => 'Alias',
        'group' => 'Group',
        'owner' => 'Owner',
        'version' => 'Version',
        'os' => 'OS',
        'username' => 'User',
        'ip' => 'IP',
        'cpu' => 'CPU',
        'memory' => 'Memory',
        'uuid' => 'UUID',
        'first_seen' => 'First Seen',
        'last_seen' => 'Last Seen',
    ];

    /** What the table showed before it was configurable — and still the default. */
    public const DEFAULT_COLUMNS = ['device', 'alias', 'group', 'owner', 'version', 'last_seen'];

    /**
     * Sort keys the browser may ask for, mapped to fixed SQL identifiers —
     * never request data, so a tampered payload cannot reach the query. The
     * three sentinel values are resolved in applySort(), which orders those by
     * a correlated subquery rather than a join so the paginator count stays
     * right. Order here is the order the mobile picker lists them in.
     */
    public const SORTABLE = [
        'id' => 'devices.rustdesk_id',
        'device' => 'devices.hostname',
        'alias' => 'devices.alias',
        'group' => '__group__',
        'owner' => '__owner__',
        'version' => 'devices.version',
        'os' => 'devices.os',
        'first_seen' => 'devices.created_at',
        'last_seen' => 'devices.last_online_at',
        'status' => '__presence__',
    ];

    /** Keys of the columns currently shown (checkbox array binding). */
    public array $columns = [];

    public bool $columnsOpen = false;

    /**
     * Current ordering (issue #27). The default reproduces what the list did
     * before it was sortable, so an existing install looks unchanged until
     * someone picks a column.
     */
    public string $sortField = 'last_seen';

    public string $sortDirection = 'desc';

    /**
     * Selected device ids for bulk actions (issue #15). Checkbox values arrive
     * as strings; every consumer re-scopes through visibleTo, so a tampered id
     * selects nothing. Selection means the CURRENT page — it clears on any
     * filter, search or page change, so it can never silently span rows the
     * operator is not looking at.
     *
     * @var array<int, string>
     */
    public array $selected = [];

    /** "Add to Address Book" picker (issue #15). */
    public bool $abPickerOpen = false;

    public int $abBookId = 0;

    /** One-line outcome of the last bulk action ("Added 4, 2 already there"). */
    public string $bulkResult = '';

    /** "Move to Group" picker (issue #47). -1 means no target selected; 0 means no group. */
    public bool $groupPickerOpen = false;

    public int $moveGroupId = -1;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: 0)]
    public int $group = 0;

    #[Url(except: 0)]
    public int $owner = 0; // 0 = any, -1 = unassigned, >0 = user id

    #[Url(except: false)]
    public bool $trashed = false;

    #[Url(except: false)]
    public bool $pendingTab = false;

    public int $perPage = 20;

    /** Device id being edited, 0 = creating, null = modal closed. */
    public ?int $editingId = null;

    public string $formRustdeskId = '';

    public string $formAlias = '';

    public string $formNote = '';

    public int $formGroupId = 0;

    public int $formUserId = 0;

    /** Device-level strategy assignment (PLAN C4). 0 = none — inherit. */
    public int $formStrategyId = 0;

    /**
     * Devices needs "View" to open (PLAN D4). Which devices are then listed is
     * still decided entirely by Device::scopeVisibleTo — a role never widens
     * the fleet, so device:rw with no device-group grant lists nothing.
     */
    public function mount(): void
    {
        $this->authorizeConsole('device', 'r');

        $saved = auth()->user()?->devices_columns;
        $this->columns = is_array($saved)
            ? array_values(array_intersect(array_keys(self::COLUMNS), $saved))
            : self::DEFAULT_COLUMNS;

        // A saved sort that is no longer allowed — a removed key, or owner
        // sorting on a user who has since lost admin — falls back to the
        // default rather than erroring or leaking an ordering they cannot pick.
        $savedSort = (string) (auth()->user()?->devices_sort ?? '');
        if ($this->canSortBy($savedSort)) {
            $this->sortField = $savedSort;
            $this->sortDirection = auth()->user()?->devices_sort_direction === 'desc' ? 'desc' : 'asc';
        }
    }

    /** Pick a column, or reverse it when it is already the active one. */
    public function sortBy(string $field): void
    {
        if (! $this->canSortBy($field)) {
            return;
        }

        $this->sortDirection = $this->sortField === $field && $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->sortField = $field;
        $this->persistSort();
    }

    /**
     * Pick a column without reversing it. The mobile picker is a select, so
     * choosing the option you are already on must not flip the direction —
     * that is what the separate reverse button is for.
     */
    public function selectSort(string $field): void
    {
        if (! $this->canSortBy($field) || $this->sortField === $field) {
            return;
        }

        $this->sortField = $field;
        $this->sortDirection = 'asc';
        $this->persistSort();
    }

    private function persistSort(): void
    {
        auth()->user()->forceFill([
            'devices_sort' => $this->sortField,
            'devices_sort_direction' => $this->sortDirection,
        ])->save();

        $this->resetPage();
        $this->clearSelection();
    }

    /** Owner ordering exposes who owns what, so it stays admin-only. */
    private function canSortBy(string $field): bool
    {
        return isset(self::SORTABLE[$field])
            && ($field !== 'owner' || (bool) auth()->user()?->is_admin);
    }

    /** Persist the column selection so it survives sign-out (issue #16). */
    public function updatedColumns(): void
    {
        // Canonical order, known keys only — the checkbox array arrives in
        // click order and a stale browser tab can post removed keys.
        $this->columns = array_values(array_intersect(array_keys(self::COLUMNS), $this->columns));

        auth()->user()->forceFill(['devices_columns' => $this->columns])->save();
    }

    public function resetColumns(): void
    {
        $this->columns = self::DEFAULT_COLUMNS;
        auth()->user()->forceFill(['devices_columns' => null])->save();
    }

    /**
     * The summary chips double as filters (issue #26): Online and Offline set
     * the status the toolbar select already drives, Devices clears it, and the
     * Pending chip opens the approval tab. Clicking a chip always returns to
     * the live list first — a filter chosen while looking at the recycle bin
     * or the pending tab means "show me those devices", not "stay here".
     */
    public function filterByChip(string $status): void
    {
        $this->trashed = false;
        $this->pendingTab = false;
        $this->status = in_array($status, ['online', 'offline'], true) ? $status : 'all';
        $this->resetPage();
        $this->clearSelection();
    }

    public function openPending(): void
    {
        $this->trashed = false;
        $this->pendingTab = true;
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedGroup(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedOwner(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedTrashed(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedPendingTab(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedPaginators($page, $pageName): void
    {
        $this->clearSelection();
    }

    public function updatedSelected(): void
    {
        $this->bulkResult = '';
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'group', 'owner', 'trashed');
        $this->resetPage();
        $this->clearSelection();
    }

    /* ---------------------------------------------------------------------
     | Bulk selection + actions (issue #15)
     * ------------------------------------------------------------------- */

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->bulkResult = '';
        $this->abPickerOpen = false;
        $this->groupPickerOpen = false;
    }

    /** Header checkbox: select every row on the current page. */
    public function selectPage(): void
    {
        $this->selected = $this->filteredQuery(auth()->user())
            ->paginate($this->perPage)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $this->bulkResult = '';
    }

    /** The selected devices this user is actually allowed to touch. */
    private function selectedDevices()
    {
        return Device::query()
            ->visibleTo(auth()->user())
            ->whereIn('id', array_map('intval', $this->selected))
            ->get();
    }

    public function bulkDelete(): void
    {
        $this->authorizeConsole('device', 'rw');

        $devices = $this->selectedDevices();
        foreach ($devices as $device) {
            Device::deleteWithStrategyContext($device);
            ConsoleAudit::record('device.delete', 'Deleted device '.$device->rustdesk_id, 'device', $device->rustdesk_id);
        }

        $count = $devices->count();
        $this->clearSelection();
        $this->bulkResult = $count.' '.Str::plural('device', $count).' moved to the recycle bin.';
    }

    /** Selected devices constrained to the current rendered page. */
    private function selectedDevicesOnCurrentPage()
    {
        $selectedIds = array_values(array_unique(array_filter(array_map('intval', $this->selected))));

        return $this->filteredQuery(auth()->user())
            ->paginate($this->perPage)
            ->getCollection()
            ->whereIn('id', $selectedIds)
            ->values();
    }

    public function openGroupPicker(): void
    {
        $this->authorizeConsole('device', 'rw');

        if ($this->selected === []) {
            return;
        }

        $this->moveGroupId = -1;
        $this->groupPickerOpen = true;
    }

    public function closeGroupPicker(): void
    {
        $this->groupPickerOpen = false;
    }

    /** Move the selected, still-visible devices to an accessible group or no group. */
    public function moveSelectedToGroup(): void
    {
        $this->authorizeConsole('device', 'rw');

        if ($this->selected === []) {
            return;
        }

        if ($this->moveGroupId < 0) {
            $this->addError('moveGroupId', 'Pick a device group.');

            return;
        }

        $group = $this->moveGroupId === 0
            ? null
            : $this->accessibleDeviceGroups()->firstWhere('id', $this->moveGroupId);
        if ($this->moveGroupId > 0 && ! $group) {
            $this->addError('moveGroupId', 'Pick an accessible device group.');

            return;
        }

        $devices = $this->selectedDevicesOnCurrentPage();
        if ($devices->isEmpty()) {
            $this->clearSelection();

            return;
        }

        $targetId = $group?->id;
        $targetName = $group?->name ?? 'No group';
        $changedIds = $devices
            ->filter(fn (Device $device) => (int) $device->device_group_id !== (int) $targetId)
            ->pluck('id')->all();
        $moved = Device::bulkUpdateStrategyContext(
            Device::query()->whereKey($changedIds ?: [0]),
            ['device_group_id' => $targetId],
            auth()->user()->consoleAllows('strategy', 'rw'),
        );
        $unchanged = $devices->count() - $moved;

        if ($moved > 0) {
            ConsoleAudit::record(
                'device.group-move',
                'Moved '.$moved.' '.Str::plural('device', $moved).' to '.$targetName.'; '.$unchanged.' unchanged.',
                'device-group',
                $targetId === null ? null : (string) $targetId,
            );
        }

        $this->clearSelection();
        $this->bulkResult = 'Moved '.$moved.' '.Str::plural('device', $moved).' to '.$targetName.'. '
            .$unchanged.' unchanged.';
    }

    public function openAbPicker(): void
    {
        $this->authorizeConsole('address_book', 'rw');

        if ($this->selected === []) {
            return;
        }

        // Ensure the personal book exists so the picker always has at least
        // one target. User-initiated (a click), so the lazy create is fine.
        AddressBook::personalFor(auth()->user());

        $this->abBookId = 0;
        $this->abPickerOpen = true;
    }

    public function closeAbPicker(): void
    {
        $this->abPickerOpen = false;
    }

    public function addSelectedToBook(): void
    {
        $this->authorizeConsole('address_book', 'rw');

        $book = $this->writableBooks()->firstWhere('id', $this->abBookId);
        if (! $book) {
            $this->addError('abBookId', 'Pick an address book.');

            return;
        }

        $existing = $book->entries()->pluck('rustdesk_id')->all();
        $added = 0;
        $skipped = 0;

        foreach ($this->selectedDevices() as $device) {
            if (in_array($device->rustdesk_id, $existing, true)) {
                $skipped++;

                continue;
            }

            $book->entries()->create([
                'rustdesk_id' => $device->rustdesk_id,
                'alias' => $device->alias ?: null,
                'hostname' => $device->hostname ?: null,
                // RustDesk-style name ("Windows", "Mac OS") — what clients
                // sync into books and match icons against. NOT platform(),
                // whose lowercase slugs are console-internal.
                'platform' => $device->rustdeskPlatform(),
                'username' => $device->username ?: null,
                'tag_ids' => [],
            ]);
            $existing[] = $device->rustdesk_id;
            $added++;
        }

        ConsoleAudit::record(
            'address-book.peer-add',
            'Added '.$added.' '.Str::plural('device', $added).' to address book '.$book->name.' from the device list',
            'address-book',
            $book->name,
        );

        $this->abPickerOpen = false;
        $this->selected = [];
        $this->bulkResult = 'Added '.$added.' to '.$book->name.'.'
            .($skipped > 0 ? ' '.$skipped.' already there.' : '');
    }

    /**
     * Books the current user may add entries to: their personal book plus any
     * shared book where their tier is read-write or better. permissionFor is
     * the same source of truth the client AB API enforces — the device screen
     * gets no wider a reach than the address-book screen would give.
     */
    private function writableBooks()
    {
        $user = auth()->user();

        if (! $this->consoleAllows('address_book', 'rw')) {
            return collect();
        }

        return AddressBook::query()
            ->with('rules')
            ->orderByDesc('is_personal')
            ->orderBy('name')
            ->get()
            ->filter(fn (AddressBook $b) => $b->permissionFor($user) >= AddressBookRule::PERM_READ_WRITE)
            ->values();
    }

    public function create(): void
    {
        $this->authorizeConsole('device', 'rw');

        $this->reset('formRustdeskId', 'formAlias', 'formNote', 'formGroupId', 'formUserId', 'formStrategyId');
        $this->editingId = 0;
    }

    /** Load a device the current user is allowed to see, or fail. */
    private function scopedDevice(int $id): Device
    {
        return Device::withTrashed()->visibleTo(auth()->user())->findOrFail($id);
    }

    /**
     * Load a gate-quarantined (pending) device the current user may act on.
     * Bypasses the approved() filter in scopeVisibleTo but keeps ownership scope.
     */
    private function scopedPendingDevice(int $id): Device
    {
        return Device::query()
            ->ownershipVisibleTo(auth()->user())
            ->pending()
            ->findOrFail($id);
    }

    public function approveDevice(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedPendingDevice($id);
        $device = Device::updateWithStrategyContext($device, ['status' => Device::STATUS_ACTIVE]);
        ConsoleAudit::record('device.approve', 'Approved device '.$device->rustdesk_id, 'device', $device->rustdesk_id);
    }

    public function rejectDevice(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedPendingDevice($id);
        $rustdeskId = $device->rustdesk_id;
        Device::deleteWithStrategyContext($device); // reject = soft-delete (quarantined + removed)
        ConsoleAudit::record('device.reject', 'Rejected device '.$rustdeskId, 'device', $rustdeskId);
    }

    public function edit(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedDevice($id);
        $this->editingId = $device->id;
        $this->formRustdeskId = $device->rustdesk_id;
        $this->formAlias = (string) $device->alias;
        $this->formNote = (string) $device->note;
        $this->formGroupId = (int) $device->device_group_id;
        $this->formUserId = (int) $device->user_id;
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
        ]);

        $attributes = [
            'alias' => $data['formAlias'] ?: null,
            'note' => $data['formNote'] ?: null,
            'device_group_id' => $data['formGroupId'] ?: null,
            'user_id' => $data['formUserId'] ?: null,
        ];

        $requestedStrategyId = (int) $data['formStrategyId'];
        $currentStrategyId = $this->editingId === 0
            ? 0
            : (int) ($this->scopedDevice($this->editingId)->assignedStrategyId() ?? 0);
        if ($requestedStrategyId !== $currentStrategyId) {
            $this->addError(
                'formStrategyId',
                'Change direct strategy assignments from Strategies → Assign so the affected-device impact can be reviewed.',
            );

            return;
        }

        if ($this->editingId === 0) {
            $this->validate(['formRustdeskId' => 'unique:devices,rustdesk_id']);
            Device::updateWithStrategyContext(new Device, $attributes + [
                'rustdesk_id' => $data['formRustdeskId'],
                'uuid' => '',
            ], auth()->user()->consoleAllows('strategy', 'rw'));
            ConsoleAudit::record('device.create', 'Created device '.$data['formRustdeskId'], 'device', $data['formRustdeskId']);
        } else {
            $device = $this->scopedDevice($this->editingId);
            DB::transaction(function () use (&$device, $attributes): void {
                $device = Device::updateWithStrategyContext(
                    $device,
                    $attributes,
                    auth()->user()->consoleAllows('strategy', 'rw'),
                );
            });
            ConsoleAudit::record('device.update', 'Updated device '.$device->rustdesk_id, 'device', $device->rustdesk_id);
        }

        $this->editingId = null;
    }

    public function closeModal(): void
    {
        $this->editingId = null;
    }

    public function deleteDevice(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedDevice($id);
        $rustdeskId = $device->rustdesk_id;
        Device::deleteWithStrategyContext($device); // soft delete → recycle bin
        ConsoleAudit::record('device.delete', 'Deleted device '.$rustdeskId, 'device', $rustdeskId);
    }

    public function restoreDevice(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedDevice($id);
        $device = Device::restoreWithStrategyContext($device);
        ConsoleAudit::record('device.restore', 'Restored device '.$device->rustdesk_id, 'device', $device->rustdesk_id);
    }

    public function forceDeleteDevice(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedDevice($id);
        $rustdeskId = $device->rustdesk_id;
        Device::deleteWithStrategyContext($device, true);
        ConsoleAudit::record('device.destroy', 'Permanently deleted device '.$rustdeskId, 'device', $rustdeskId);
    }

    public function render()
    {
        $user = auth()->user();

        $pendingCount = Device::query()->ownershipVisibleTo($user)->pending()->count();

        if ($this->pendingTab) {
            return $this->renderPending($user, $pendingCount);
        }

        $devices = $this->filteredQuery($user)->paginate($this->perPage);

        // Which optional columns render, keyed for the blade. Owner stays
        // admin-only regardless of what a saved selection claims.
        $cols = [];
        foreach (array_keys(self::COLUMNS) as $key) {
            $cols[$key] = in_array($key, $this->columns, true)
                && ($key !== 'owner' || $user->is_admin);
        }

        $groups = $this->accessibleDeviceGroups();

        return view('livewire.device-list', [
            'devices' => $devices,
            'cols' => $cols,
            // Select + ID + Status + Action + the visible optional columns.
            'colspan' => 4 + count(array_filter($cols)),
            'books' => $this->abPickerOpen ? $this->writableBooks() : collect(),
            'groups' => $groups,
            'users' => User::orderBy('username')->get(['id', 'username']),
            'strategies' => $this->editorStrategies(),
            'strategyExplain' => $this->editorStrategyExplain(),
            'totalCount' => Device::visibleTo($user)->count(),
            'onlineCount' => Device::visibleTo($user)->online()->count(),
            'trashedCount' => Device::visibleTo($user)->onlyTrashed()->count(),
            'pendingCount' => $pendingCount,
        ]);
    }

    /** Device groups this actor may see and therefore may choose as move targets. */
    private function accessibleDeviceGroups()
    {
        $user = auth()->user();

        return DeviceGroup::orderBy('name')
            ->when(! $user->seesAllDevices(), fn ($q) => $q->whereIn('id', $user->accessibleDeviceGroupIds() ?: [0]))
            ->get();
    }

    /**
     * The list the screen is showing right now — scope, filters and order —
     * shared by render() and the CSV export so the two can never disagree
     * about what "the current view" means.
     */
    private function filteredQuery(User $user)
    {
        $query = Device::query()
            ->visibleTo($user)
            ->with(['group', 'user'])
            ->when($this->trashed, fn ($q) => $q->onlyTrashed())
            ->when($this->search !== '', function ($q) {
                $s = '%'.$this->search.'%';
                $q->where(function ($q) use ($s) {
                    $q->where('rustdesk_id', 'like', $s)
                        ->orWhere('alias', 'like', $s)
                        ->orWhere('hostname', 'like', $s)
                        ->orWhere('username', 'like', $s)
                        ->orWhere('last_online_ip', 'like', $s);
                });
            })
            ->when(! $this->trashed && $this->status === 'online', fn ($q) => $q->online())
            ->when(! $this->trashed && $this->status === 'offline', fn ($q) => $q->offline())
            ->when($this->group > 0, fn ($q) => $q->where('device_group_id', $this->group))
            ->when($this->owner === -1, fn ($q) => $q->whereNull('user_id'))
            ->when($this->owner > 0, fn ($q) => $q->where('user_id', $this->owner));

        return $this->applySort($query);
    }

    /**
     * Order by the chosen column, blanks last, with a rustdesk_id tie-breaker.
     *
     * The tie-breaker is the point of the exercise (issue #27): without it,
     * rows with equal keys come back in whatever order the engine feels like
     * and the list reshuffles under wire:poll while you are editing it.
     */
    private function applySort($query)
    {
        // Re-validated rather than trusted: the properties are public, so a
        // crafted Livewire payload can set them to anything.
        $field = $this->canSortBy($this->sortField) ? $this->sortField : 'last_seen';
        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        if ($field === 'status') {
            // Presence is derived, not stored, so it sorts by the same window
            // the badge uses rather than by raw last_online_at.
            $query->orderByRaw(
                "CASE WHEN devices.last_online_at > ? THEN 1 ELSE 0 END {$direction}",
                [now()->subSeconds(Device::onlineWindow())],
            );
        } elseif ($field === 'group' || $field === 'owner') {
            [$model, $column, $fk] = $field === 'group'
                ? [DeviceGroup::class, 'name', 'devices.device_group_id']
                : [User::class, 'username', 'devices.user_id'];

            $query->orderByRaw("{$fk} is null")
                ->orderBy(
                    $model::query()
                        ->select($column)
                        ->whereColumn((new $model)->getTable().'.id', $fk),
                    $direction
                );
        } else {
            $column = self::SORTABLE[$field];

            // Empty strings sort with the blanks, not between them: a device
            // that reported an empty hostname is missing one, not named "".
            if (in_array($field, ['device', 'alias', 'version', 'os'], true)) {
                $query->orderByRaw("case when {$column} is null or {$column} = '' then 1 else 0 end");
            } elseif ($field === 'last_seen') {
                $query->orderByRaw("{$column} is null");
            }

            $query->orderBy($column, $direction);
        }

        return $query->orderBy('devices.rustdesk_id');
    }

    /**
     * Stream the current view as CSV (issue #16). The first fifteen headers
     * match lejianwen/rustdesk-api's device export byte for byte so tooling
     * built against that format keeps working; CortenDesk-specific columns
     * are appended after. Scoping is the list's own: a non-admin exports only
     * the devices they can see.
     */
    public function exportCsv()
    {
        $this->authorizeConsole('device', 'r');

        $rows = $this->filteredQuery(auth()->user())->get();

        ConsoleAudit::record('device.export', 'Exported '.$rows->count().' devices to CSV', 'device', '');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'row_id', 'id', 'cpu', 'hostname', 'memory', 'os', 'username', 'uuid',
                'version', 'last_online_time', 'last_online_ip', 'group_id', 'alias',
                'created_at', 'updated_at',
                'group_name', 'owner', 'status', 'note', 'registered_ip',
            ]);
            foreach ($rows as $d) {
                fputcsv($out, [
                    $d->id,
                    $d->rustdesk_id,
                    $d->cpu,
                    $d->hostname,
                    $d->memory,
                    $d->os,
                    $d->username,
                    $d->uuid,
                    $d->version,
                    $d->last_online_at?->toDateTimeString(),
                    $d->last_online_ip,
                    $d->device_group_id,
                    $d->alias,
                    $d->created_at?->toDateTimeString(),
                    $d->updated_at?->toDateTimeString(),
                    $d->group?->name,
                    $d->user?->username,
                    $d->trashed() ? 'disabled' : ($d->isPending() ? 'pending' : 'active'),
                    $d->note,
                    $d->registered_ip,
                ]);
            }
            fclose($out);
        }, 'devices.csv');
    }

    /**
     * Strategies offered by the editor's assignment select. Empty unless an
     * admin has the editor open on an existing device, so the device list keeps
     * costing exactly what it cost before strategies existed.
     */
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

    /** Render the "Pending" approval queue (gate-quarantined devices). */
    private function renderPending(User $user, int $pendingCount)
    {
        $devices = Device::query()
            ->ownershipVisibleTo($user)
            ->pending()
            ->with(['group', 'user'])
            ->when($this->search !== '', function ($q) {
                $s = '%'.$this->search.'%';
                $q->where(function ($q) use ($s) {
                    $q->where('rustdesk_id', 'like', $s)
                        ->orWhere('hostname', 'like', $s)
                        ->orWhere('username', 'like', $s)
                        ->orWhere('last_online_ip', 'like', $s);
                });
            })
            ->orderByDesc('created_at')
            ->paginate($this->perPage);

        return view('livewire.device-list-pending', [
            'devices' => $devices,
            'pendingCount' => $pendingCount,
        ]);
    }
}
