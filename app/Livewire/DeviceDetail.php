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
use App\Models\NotificationDelivery;
use App\Services\DeviceAddressBooks;
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

    public int $deviceId;

    public string $note = '';

    public bool $editingNote = false;

    /** Inline "Add to address book" picker. */
    public bool $abPickerOpen = false;

    public int $abBookId = 0;

    /** Outcome of the last address-book change. */
    public string $abResult = '';

    public function mount(int $deviceId): void
    {
        $this->authorizeConsole('device', 'r');
        $this->deviceId = $deviceId;
        $this->note = (string) $this->device()->note;
    }

    /**
     * Re-resolved on every request, never cached on the component: visibility
     * can change between polls, and a device deleted meanwhile must 404 the
     * next interaction rather than serve stale facts.
     */
    private function device(): Device
    {
        return Device::visibleTo(auth()->user())
            ->withTrashed()
            ->with(['group', 'user', 'resolvedStrategy'])
            ->findOrFail($this->deviceId);
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

    /** Confirmed delete: back to the list, which shows the outcome. */
    public function performDelete()
    {
        $this->deleteIds = [$this->deviceId];
        session()->flash('devices.result', $this->deleteConfirmed());

        return $this->redirectRoute('devices');
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
            $this->addError('abBookId', 'Pick an address book.');

            return;
        }

        if ($book->entries()->where('rustdesk_id', $device->rustdesk_id)->exists()) {
            $this->abResult = 'Already in '.$book->name.'.';
        } else {
            $book->entries()->create(DeviceAddressBooks::entryAttributes($device));
            ConsoleAudit::record(
                'address-book.peer-add',
                'Added 1 device to address book '.$book->name.' from the device detail page',
                'address-book',
                $book->name,
            );
            $this->abResult = 'Added to '.$book->name.'.';
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

        $this->abResult = 'Removed from '.$book->name.'.';
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

        return view('livewire.device-detail', $this->editorViewData() + [
            'device' => $device,
            'books' => $books,
            'writableBookIds' => $canWriteBooks
                ? $books->filter(fn (AddressBook $b) => DeviceAddressBooks::canWrite($user, $b, $groupIds))->pluck('id')->all()
                : [],
            'addableBooks' => $addableBooks,
            'canAudit' => $canAudit,
            'canEdit' => $user->consoleAllows('device', 'rw'),
            'canWriteBooks' => $canWriteBooks,
            'deleteState' => $this->deleteConfirmState(),
            'connections' => $canAudit
                ? AuditConnection::where('rustdesk_id', $device->rustdesk_id)->with('noteUser')->latest()->limit(10)->get()
                : collect(),
            'transfers' => $canAudit
                ? AuditFileTransfer::where('rustdesk_id', $device->rustdesk_id)->latest()->limit(10)->get()
                : collect(),
            'alarms' => $canAudit
                ? AlarmLog::where('rustdesk_id', $device->rustdesk_id)->latest()->limit(10)->get()
                : collect(),
            'notifications' => $canAudit ? $this->notifications($device) : collect(),
        ]);
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
