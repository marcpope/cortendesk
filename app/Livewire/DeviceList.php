<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Livewire\Concerns\DeletesDevices;
use App\Livewire\Concerns\EditsDevices;
use App\Models\AddressBook;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use App\Services\DeviceAddressBooks;
use App\Services\DuplicateDevices;
use App\Support\Csv;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class DeviceList extends Component
{
    use AuthorizesConsole, DeletesDevices, EditsDevices, WithPagination;

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
        'lan_ip' => 'LAN IP',
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

    /** Per-request memo of the viewer's duplicate pairs; never sent to the browser. */
    private ?DuplicateDevices $duplicates = null;

    /**
     * Devices needs "View" to open (PLAN D4). Which devices are then listed is
     * still decided entirely by Device::scopeVisibleTo — a role never widens
     * the fleet, so device:rw with no device-group grant lists nothing.
     */
    public function mount(): void
    {
        $this->authorizeConsole('device', 'r');

        // Outcome of a delete made on the device detail page, which lands here.
        $this->bulkResult = (string) session('devices.result', '');

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
     * the status the toolbar select already drives, Devices clears it,
     * Possible duplicates (issue #91) narrows to flagged devices, and the
     * Pending chip opens the approval tab. Clicking a chip always returns to
     * the live list first — a filter chosen while looking at the recycle bin
     * or the pending tab means "show me those devices", not "stay here".
     */
    public function filterByChip(string $status): void
    {
        $this->trashed = false;
        $this->pendingTab = false;
        $this->status = in_array($status, ['online', 'offline', 'duplicates'], true) ? $status : 'all';
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

    /** Bulk "Delete": opens the confirm step (issue #85). */
    public function confirmDeleteSelected(): void
    {
        if ($this->selected !== []) {
            $this->openDeleteConfirm($this->selected);
        }
    }

    /** Row "Delete": opens the confirm step (issue #85). */
    public function confirmDelete(int $id): void
    {
        $this->authorizeConsole('device', 'rw');
        $this->scopedDevice($id); // 404 outside the actor's fleet
        $this->openDeleteConfirm([$id]);
    }

    /** The confirm step's Delete button. */
    public function performDelete(): void
    {
        $message = $this->deleteConfirmed();
        $this->clearSelection();
        $this->bulkResult = $message;
    }

    /** Delete the selection without the confirm step; address books untouched. */
    public function bulkDelete(): void
    {
        $this->deleteIds = $this->selected;
        $this->performDelete();
    }

    /** Delete one device without the confirm step; address books untouched. */
    public function deleteDevice(int $id): void
    {
        $this->authorizeConsole('device', 'rw');
        $this->scopedDevice($id); // 404 outside the actor's fleet
        $this->deleteIds = [$id];
        $this->performDelete();
    }

    /**
     * Mark the selected devices incoming only, or let them start sessions
     * again (issue #82). The ID server picks it up on its next policy fetch.
     */
    public function setIncomingOnly(bool $incomingOnly): void
    {
        $this->authorizeConsole('device', 'rw');

        $changed = 0;
        foreach ($this->selectedDevices() as $device) {
            if ($device->isIncomingOnly() === $incomingOnly) {
                continue;
            }
            $device->update(['can_initiate' => ! $incomingOnly]);
            $changed++;
        }

        $label = $incomingOnly ? 'incoming only' : 'allowed to start sessions';
        if ($changed > 0) {
            ConsoleAudit::record('device.initiate', 'Set '.$changed.' '.Str::plural('device', $changed).' '.$label, 'device', '');
        }

        $this->clearSelection();
        $this->bulkResult = $changed.' '.Str::plural('device', $changed).' set '.$label.'.';
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
        $moved = 0;
        $unchanged = 0;

        foreach ($devices as $device) {
            if ((int) $device->device_group_id === (int) $targetId) {
                $unchanged++;

                continue;
            }

            $device->update(['device_group_id' => $targetId]);
            $moved++;
        }

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

            $book->entries()->create(DeviceAddressBooks::entryAttributes($device));
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

    /** Books the current user may add entries to (same rule as the address-book screen). */
    private function writableBooks()
    {
        return DeviceAddressBooks::writableBooks(auth()->user());
    }

    public function create(): void
    {
        $this->authorizeConsole('device', 'rw');

        $this->reset('formRustdeskId', 'formAlias', 'formNote', 'formGroupId', 'formUserId', 'formStrategyId', 'formCanInitiate');
        $this->editingId = 0;
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
        $device->update(['status' => Device::STATUS_ACTIVE]);
        ConsoleAudit::record('device.approve', 'Approved device '.$device->rustdesk_id, 'device', $device->rustdesk_id);
    }

    public function rejectDevice(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedPendingDevice($id);
        $rustdeskId = $device->rustdesk_id;
        $device->delete(); // reject = soft-delete (quarantined + removed)
        ConsoleAudit::record('device.reject', 'Rejected device '.$rustdeskId, 'device', $rustdeskId);
    }

    public function restoreDevice(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedDevice($id);
        $device->restore();
        ConsoleAudit::record('device.restore', 'Restored device '.$device->rustdesk_id, 'device', $device->rustdesk_id);
    }

    public function forceDeleteDevice(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->scopedDevice($id);
        $rustdeskId = $device->rustdesk_id;
        $device->forceDelete();
        ConsoleAudit::record('device.destroy', 'Permanently deleted device '.$rustdeskId, 'device', $rustdeskId);
    }

    /**
     * Likely duplicates among the devices this viewer can see (issue #91).
     * Scoped through visibleTo, so a badge never names a device outside the
     * viewer's fleet.
     */
    private function duplicates(): DuplicateDevices
    {
        return $this->duplicates ??= DuplicateDevices::in(Device::query()->visibleTo(auth()->user()));
    }

    private function duplicatesMode(): bool
    {
        return ! $this->trashed && $this->status === 'duplicates';
    }

    public function render()
    {
        $user = auth()->user();
        // Actions earlier in this request may have deleted a twin.
        $this->duplicates = null;

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

        return view('livewire.device-list', $this->editorViewData() + [
            'devices' => $devices,
            'cols' => $cols,
            // Select + ID + Status + Action + the visible optional columns.
            'colspan' => 4 + count(array_filter($cols)),
            'books' => $this->abPickerOpen ? $this->writableBooks() : collect(),
            'groups' => $groups,
            'users' => User::orderBy('username')->get(['id', 'username']),
            'deleteState' => $this->deleteConfirmState(),
            'totalCount' => Device::visibleTo($user)->count(),
            'onlineCount' => Device::visibleTo($user)->online()->count(),
            'trashedCount' => Device::visibleTo($user)->onlyTrashed()->count(),
            'pendingCount' => $pendingCount,
            'duplicates' => $this->trashed ? null : $this->duplicates(),
        ]);
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
            ->when($this->duplicatesMode(), fn ($q) => $q->whereIn('devices.id', $this->duplicates()->ids()))
            ->when($this->group > 0, fn ($q) => $q->where('device_group_id', $this->group))
            ->when($this->owner === -1, fn ($q) => $q->whereNull('user_id'))
            ->when($this->owner > 0, fn ($q) => $q->where('user_id', $this->owner));

        // Possible duplicates: twins sit together, the chosen sort applies
        // within each set.
        if ($this->duplicatesMode() && ($order = $this->duplicates()->clusterOrder()) !== []) {
            // Integers from our own query, cast again, so inlining is safe and
            // keeps a large fleet clear of placeholder limits.
            $cases = '';
            foreach ($order as $id => $position) {
                $cases .= ' WHEN '.(int) $id.' THEN '.(int) $position;
            }
            $query->orderByRaw('CASE devices.id'.$cases.' ELSE '.count($order).' END');
        }

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
            fputcsv($out, Csv::row([
                'row_id', 'id', 'cpu', 'hostname', 'memory', 'os', 'username', 'uuid',
                'version', 'last_online_time', 'last_online_ip', 'group_id', 'alias',
                'created_at', 'updated_at',
                'group_name', 'owner', 'status', 'note', 'registered_ip',
                'incoming_only', 'lan_ip', 'lan_ip_seen_at',
            ]));
            foreach ($rows as $d) {
                fputcsv($out, Csv::row([
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
                    $d->isIncomingOnly() ? 1 : 0,
                    $d->lan_ip,
                    $d->lan_ip_seen_at?->toDateTimeString(),
                ]));
            }
            fclose($out);
        }, 'devices.csv');
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
