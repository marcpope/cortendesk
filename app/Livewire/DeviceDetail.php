<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Livewire\Concerns\DeletesDevices;
use App\Livewire\Concerns\EditsDevices;
use App\Models\AddressBook;
use App\Models\AlarmLog;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceDuplicateDismissal;
use App\Models\NotificationDelivery;
use App\Services\DeviceAddressBooks;
use App\Services\DuplicateDevices;
use App\Services\ReleaseId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One device, everything the console knows about it (issues #35, #54):
 * identity and hardware, an editable note, the list's Edit and Delete, which
 * address books carry it, and its recent activity. The activity sections
 * reuse the audit tables and are gated by the audit permission, same as the
 * log screens they excerpt.
 */
class DeviceDetail extends Component
{
    use AuthorizesConsole, DeletesDevices, EditsDevices;

    public const TABS = ['overview', 'connections', 'activity', 'details'];

    public int $deviceId;

    /** Every pane is rendered; this only picks the visible one. */
    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public string $note = '';

    public bool $editingNote = false;

    /** Inline "Add to address book" picker. */
    public bool $abPickerOpen = false;

    public int $abBookId = 0;

    /** Outcome of the last address-book change. */
    public string $abResult = '';

    /** Outcome of the last action on a possible duplicate. */
    public string $duplicateResult = '';

    /** Outcome of the last Release ID, and whether it worked. */
    public string $releaseResult = '';

    public bool $releaseOk = false;

    /** Per-request memo of the viewer's scope; resolving it costs queries. */
    private ?Builder $visibleScope = null;

    public function mount(int $deviceId): void
    {
        $this->authorizeConsole('device', 'r');
        $this->deviceId = $deviceId;
        $this->note = (string) $this->device()->note;
        // The list's Share action lands on the share card (?share=1#share).
        if (request()->boolean('share')) {
            $this->tab = 'details';
        }
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'overview';
    }

    /** The Note tile on the overview: edit it where it lives. */
    public function openNote(): void
    {
        $this->tab = 'details';
        $this->editNote();
    }

    /** The Share screen tile: open the new-link form on the share card. */
    public function startShare(): void
    {
        $this->tab = 'details';
        $this->dispatch('device-share-start')->to(DeviceShares::class);
    }

    /**
     * Re-resolved on every request, never cached on the component: visibility
     * can change between polls, and a device deleted meanwhile must 404 the
     * next interaction rather than serve stale facts.
     */
    private function device(): Device
    {
        return $this->visibleDevices()
            ->withTrashed()
            ->with(['group', 'user', 'resolvedStrategy'])
            ->findOrFail($this->deviceId);
    }

    /** A fresh copy of the viewer's device scope (Device::visibleTo). */
    private function visibleDevices(): Builder
    {
        return clone ($this->visibleScope ??= Device::query()->visibleTo(auth()->user()));
    }

    public function editNote(): void
    {
        $this->authorizeConsole('device', 'rw');
        $this->note = (string) $this->device()->note;
        $this->editingNote = true;
    }

    public function saveNote(): void
    {
        $this->authorizeConsole('device', 'rw');
        // The column is 500 characters; the list's editor enforces the same.
        $this->validate(['note' => ['nullable', 'string', 'max:500']]);

        $device = $this->device();
        $device->update(['note' => trim($this->note)]);
        ConsoleAudit::record('device.update', 'Updated note on device '.$device->rustdesk_id, 'device', $device->rustdesk_id);

        $this->editingNote = false;
    }

    public function cancelNote(): void
    {
        $this->note = (string) $this->device()->note;
        $this->editingNote = false;
    }

    /**
     * Header "Release ID" (cortendesk-server #19): free this device's ID on
     * the bundled ID server, so a reinstalled machine can take it again.
     */
    public function releaseId(ReleaseId $releaser): void
    {
        $this->authorizeConsole('device', 'rw');
        $device = $this->device();

        $outcome = $releaser->release($device->rustdesk_id);
        $this->releaseOk = in_array($outcome, [ReleaseId::RELEASED, ReleaseId::RELEASED_ONLINE], true);
        $id = ['id' => $device->rustdesk_id];
        $this->releaseResult = match ($outcome) {
            ReleaseId::RELEASED => __('devices.release_id_released', $id),
            ReleaseId::RELEASED_ONLINE => __('devices.release_id_released_online', $id),
            ReleaseId::NOT_FOUND => __('devices.release_id_not_found', $id),
            ReleaseId::UNAVAILABLE => __('devices.release_id_unavailable'),
            default => __('devices.release_id_failed', $id),
        };

        if ($this->releaseOk) {
            ConsoleAudit::record('device.release-id', 'Released ID '.$device->rustdesk_id.' on the ID server', 'device', $device->rustdesk_id);
        }
    }

    /** Header "Edit": the list's editor, for this device only. */
    public function editDevice(): void
    {
        $this->edit($this->deviceId);
    }

    /** Header "Delete": the list's confirm step, for this device only. */
    public function confirmDelete(): void
    {
        $this->openDeleteConfirm([$this->deviceId]);
    }

    /**
     * Confirmed delete. A possible duplicate deleted from the callout leaves
     * this page in place; anything else deletes this device and returns to
     * the list, which shows the outcome.
     */
    public function performDelete()
    {
        $ids = array_map('intval', $this->deleteIds ?? []);
        if (count($ids) === 1 && $ids[0] !== $this->deviceId && $this->isTwin($ids[0])) {
            $this->duplicateResult = $this->deleteConfirmed();

            return null;
        }

        $this->deleteIds = [$this->deviceId];
        session()->flash('devices.result', $this->deleteConfirmed());

        return $this->redirectRoute('devices');
    }

    /* ---------------------------------------------------------------------
     | Possible duplicates (issue #91)
     * ------------------------------------------------------------------- */

    /**
     * Devices this one looks like, among those the viewer can see. Scoped by
     * visibleTo, so the callout never names a device outside their fleet.
     *
     * @return list<array{device: Device, reason: string, label: string}>
     */
    private function twins(Device $device): array
    {
        return DuplicateDevices::of($device, $this->visibleDevices())->twinsOf($device->id);
    }

    private function isTwin(int $id): bool
    {
        return in_array($id, array_map(fn ($t) => $t['device']->id, $this->twins($this->device())), true);
    }

    /** Callout "Delete": the usual confirm step, for the other device. */
    public function confirmDeleteTwin(int $id): void
    {
        $this->authorizeConsole('device', 'rw');
        abort_unless($this->isTwin($id), 404);

        $this->duplicateResult = '';
        $this->openDeleteConfirm([$id]);
    }

    /** Callout "Not a duplicate": stop pairing these two, and audit it. */
    public function dismissDuplicate(int $id): void
    {
        $this->authorizeConsole('device', 'rw');

        $device = $this->device();
        $twin = collect($this->twins($device))->firstWhere('device.id', $id)['device'] ?? null;
        abort_if($twin === null, 404);

        DeviceDuplicateDismissal::dismiss($device->id, $twin->id, auth()->id());
        ConsoleAudit::record(
            'device.duplicate-dismiss',
            'Marked devices '.$device->rustdesk_id.' and '.$twin->rustdesk_id.' as separate machines',
            'device',
            $device->rustdesk_id,
        );

        $this->duplicateResult = 'Devices '.$device->rustdesk_id.' and '.$twin->rustdesk_id.' will no longer be flagged.';
    }

    public function openAbPicker(): void
    {
        $this->authorizeConsole('address_book', 'rw');

        // Same as the list: the personal book always exists as a target.
        AddressBook::personalFor(auth()->user());

        $this->abBookId = 0;
        $this->abResult = '';
        $this->abPickerOpen = true;
    }

    public function closeAbPicker(): void
    {
        $this->abPickerOpen = false;
        $this->resetValidation('abBookId');
    }

    public function addToBook(): void
    {
        $this->authorizeConsole('address_book', 'rw');

        $device = $this->device();
        abort_if($device->trashed(), 404);

        $book = DeviceAddressBooks::writableBooks(auth()->user())->firstWhere('id', $this->abBookId);
        if (! $book) {
            $this->addError('abBookId', __('messages.pick_address_book'));

            return;
        }

        if ($book->entries()->where('rustdesk_id', $device->rustdesk_id)->exists()) {
            $this->abResult = __('messages.already_in_book', ['book' => $book->name]);
        } else {
            $book->entries()->create(DeviceAddressBooks::entryAttributes($device));
            ConsoleAudit::record(
                'address-book.peer-add',
                'Added 1 device to address book '.$book->name.' from the device detail page',
                'address-book',
                $book->name,
            );
            $this->abResult = __('messages.added_to_book_single', ['book' => $book->name]);
        }

        $this->abPickerOpen = false;
    }

    public function removeFromBook(int $bookId): void
    {
        $this->authorizeConsole('address_book', 'rw');

        $device = $this->device();
        $book = AddressBook::with('rules')->findOrFail($bookId);
        abort_unless(DeviceAddressBooks::canWrite(auth()->user(), $book), 403);

        $removed = $book->entries()->where('rustdesk_id', $device->rustdesk_id)->delete();
        if ($removed > 0) {
            ConsoleAudit::record(
                'address-book.peer-delete',
                'Removed device '.$device->rustdesk_id.' from address book '.$book->name.' on the device detail page',
                'address-book',
                $book->name,
            );
        }

        $this->abResult = __('messages.removed_from_book', ['book' => $book->name]);
    }

    public function render()
    {
        $user = auth()->user();
        $device = $this->device();
        $canAudit = $user->consoleAllows('audit');
        $canWriteBooks = $user->consoleAllows('address_book', 'rw');

        // Only books this user could open anyway — membership alone must not
        // reveal a shared book they have no rule for.
        $groupIds = $user->is_admin
            ? []
            : $user->groups()->pluck('user_groups.id')->all();
        $books = AddressBook::query()
            ->whereHas('entries', fn ($q) => $q->where('rustdesk_id', $device->rustdesk_id))
            ->with(['owner', 'rules'])
            ->get()
            ->filter(fn (AddressBook $b) => $b->permissionFor($user, $groupIds) > 0)
            ->values();

        // Books the device can still be added to; only built while the picker
        // is open so the page does not load every book on each poll.
        $addableBooks = $this->abPickerOpen
            ? DeviceAddressBooks::writableBooks($user)->whereNotIn('id', $books->pluck('id'))->values()
            : collect();

        $connections = $canAudit
            ? $this->both(AuditConnection::query(), $device)->with(['noteUser', 'relaySession'])->latest()->limit(10)->get()
            : collect();
        $transfers = $canAudit
            ? $this->both(AuditFileTransfer::query(), $device)->latest()->limit(10)->get()
            : collect();
        $peerNames = $this->peerNames($device, $connections->concat($transfers));

        return view('livewire.device-detail', $this->editorViewData() + [
            'device' => $device,
            'books' => $books,
            'writableBookIds' => $canWriteBooks
                ? $books->filter(fn (AddressBook $b) => DeviceAddressBooks::canWrite($user, $b, $groupIds))->pluck('id')->all()
                : [],
            'addableBooks' => $addableBooks,
            'canAudit' => $canAudit,
            'canEdit' => $user->consoleAllows('device', 'rw'),
            'canReleaseId' => ReleaseId::available(),
            'canWriteBooks' => $canWriteBooks,
            'deleteState' => $this->deleteConfirmState(),
            'twins' => $this->twins($device),
            'connections' => $connections,
            'transfers' => $transfers,
            'peerNames' => $peerNames,
            'flow' => self::flow($device, $connections, $peerNames),
            'alarms' => $canAudit
                ? AlarmLog::where('rustdesk_id', $device->rustdesk_id)->latest()->limit(10)->get()
                : collect(),
            'notifications' => $canAudit ? $this->notifications($device) : collect(),
            'hardware' => self::hardware($device),
        ]);
    }

    /**
     * The overview's hardware tiles, from what sysinfo carries. The client
     * reports the CPU as "<brand>, <current>GHz, <logical>/<physical> cores";
     * older clients send the brand alone.
     *
     * @return array{cpu: string, cpu_detail: string, memory: string, os: string, os_detail: string}
     */
    public static function hardware(Device $device): array
    {
        $parts = array_map('trim', explode(',', (string) $device->cpu));
        $brand = $parts[0] ?? '';
        // Intel brands carry "(R)", "(TM)", "CPU", the base clock and, on
        // some Xeons, a lone "0" for the first revision.
        $name = trim(preg_replace(['/\((R|TM)\)/i', '/\s+CPU\b/i', '/\s*@\s*[\d.]+\s*GHz/i', '/\s+0$/', '/\s+/'], ['', '', '', '', ' '], $brand));

        $freq = null;
        $cores = null;
        $threads = null;
        foreach (array_slice($parts, 1) as $part) {
            if (preg_match('/^([\d.]+)\s*GHz$/i', $part, $m)) {
                $freq = $m[1];
            } elseif (preg_match('/^(\d+)\/(\d+)\s*cores?$/i', $part, $m)) {
                [$threads, $cores] = [(int) $m[1], (int) $m[2]];
            }
        }
        if ($freq === null && preg_match('/@\s*([\d.]+)\s*GHz/i', $brand, $m)) {
            $freq = $m[1];
        }

        $detail = [];
        if ($cores) {
            $detail[] = trans_choice('devices.hw_cores', $cores);
            if ($threads && $threads !== $cores) {
                $detail[] = trans_choice('devices.hw_threads', $threads);
            }
        }
        if ($freq !== null) {
            $detail[] = $freq.' GHz';
        }

        // "31.94GB" reads as the 32 GB the machine was sold with.
        $memory = (string) $device->memory;
        if (preg_match('/^([\d.]+)\s*GB$/i', trim($memory), $m) && (float) $m[1] >= 2) {
            $memory = round((float) $m[1]).' GB';
        }

        // Windows reports "Windows 11 Pro - 11 (26200)": the build moves under the name.
        $os = $device->osDescription();
        $osDetail = '';
        if (preg_match('/^(.*?)\s+-\s+[\d.]+\s*\((\d+)\)$/', $os, $m)) {
            [$os, $osDetail] = [$m[1], __('devices.hw_build', ['build' => $m[2]])];
        }

        return ['cpu' => $name, 'cpu_detail' => implode(' · ', $detail), 'memory' => $memory, 'os' => $os, 'os_detail' => $osDetail];
    }

    /**
     * The overview's connection map from the recent sessions: who reached
     * this device, and what it reached, busiest first. Each side keeps three
     * nodes; the rest fold into a count.
     *
     * @param  array<string, array{0: string, 1: ?int, 2?: string}>  $peerNames
     * @return array{in: list<array>, out: list<array>, in_more: int, out_more: int, in_count: int, out_count: int}
     */
    public static function flow(Device $device, Collection $connections, array $peerNames): array
    {
        $node = fn (string $label, ?int $id, string $platform, int $count) => compact('label', 'id', 'platform', 'count');

        $in = $connections->where('rustdesk_id', $device->rustdesk_id)
            ->groupBy(fn (AuditConnection $c) => $c->from_peer ?: 'name:'.$c->from_name)
            ->map(function (Collection $rows) use ($peerNames, $node) {
                $c = $rows->first();
                $known = $c->from_peer ? ($peerNames[$c->from_peer] ?? null) : null;

                return $node($known[0] ?? ($c->from_name ?: $c->from_peer ?: __('common.unknown')), $known[1] ?? null, $known[2] ?? '', $rows->count());
            })
            ->sortByDesc('count')->values();

        $out = $connections->where('rustdesk_id', '!=', $device->rustdesk_id)
            ->groupBy('rustdesk_id')
            ->map(function (Collection $rows, string $rid) use ($peerNames, $node) {
                $known = $peerNames[$rid] ?? [$rid, null];

                return $node($known[0], $known[1], $known[2] ?? '', $rows->count());
            })
            ->sortByDesc('count')->values();

        return [
            'in' => $in->take(3)->all(),
            'out' => $out->take(3)->all(),
            'in_more' => max(0, $in->count() - 3),
            'out_more' => max(0, $out->count() - 3),
            'in_count' => (int) $in->sum('count'),
            'out_count' => (int) $out->sum('count'),
        ];
    }

    /**
     * Log rows for sessions to this device and, since #149, sessions it
     * started (it is the controlling peer). Outgoing rows are scoped like the
     * logs: a user who sees only some devices sees only sessions to those.
     */
    private function both(Builder $query, Device $device): Builder
    {
        $user = auth()->user();

        return $query->where(fn (Builder $q) => $q
            ->where('rustdesk_id', $device->rustdesk_id)
            ->orWhere(fn (Builder $q) => $q
                ->where('from_peer', $device->rustdesk_id)
                ->when(! $user->seesAllDevices(), fn (Builder $q) => $q->whereIn(
                    'rustdesk_id',
                    Device::query()->visibleTo($user)->select('rustdesk_id')
                ))));
    }

    /**
     * Names for the other end of each log row: the device this one reached on
     * an outgoing row (#149), and the device that connected on an incoming one
     * when the console knows it. RustDesk ID => [label, device id or null when
     * the user cannot open it, platform slug or '']. Incoming peers are listed
     * only when visible.
     *
     * @return array<string, array{0: string, 1: ?int, 2: string}>
     */
    private function peerNames(Device $device, Collection $rows): array
    {
        $outgoing = $rows->where('rustdesk_id', '!=', $device->rustdesk_id)->pluck('rustdesk_id')->unique();
        $incoming = $rows->where('rustdesk_id', $device->rustdesk_id)->pluck('from_peer')->filter()->unique();
        $ids = $outgoing->merge($incoming)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $visible = Device::query()->visibleTo(auth()->user())->whereIn('rustdesk_id', $ids)
            ->get(['id', 'rustdesk_id', 'alias', 'hostname', 'os'])->keyBy('rustdesk_id');

        return $ids->filter(fn (string $rid) => $visible->has($rid) || $outgoing->contains($rid))
            ->mapWithKeys(function (string $rid) use ($visible) {
                $d = $visible->get($rid);

                return [$rid => [$d ? ($d->alias ?: $d->hostname ?: $rid) : $rid, $d?->id, $d ? $d->platform() : '']];
            })->all();
    }

    /**
     * Apprise deliveries about this device. Device events carry
     * "device:<rustdesk id>" as their subject; security alarms carry
     * "alarm:<alarm id>", matched through this device's recent alarms.
     */
    private function notifications(Device $device)
    {
        $alarmSubjects = AlarmLog::where('rustdesk_id', $device->rustdesk_id)
            ->latest('id')->limit(100)->pluck('id')
            ->map(fn ($id) => 'alarm:'.$id)->all();

        return NotificationDelivery::query()
            ->whereIn('subject', ['device:'.$device->rustdesk_id, ...$alarmSubjects])
            ->latest()->orderByDesc('id')
            ->limit(10)
            ->get();
    }
}
