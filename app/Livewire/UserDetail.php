<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\AddressBook;
use App\Models\AuditConnection;
use App\Models\ClientToken;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * One user, everything the console knows about them (issue #55): profile,
 * devices they own, address books they own, and recent activity. Read-only;
 * changes stay on the Users list.
 *
 * Each section checks the permission its own screen needs: sign-ins and
 * console actions need audit "Manage" (like /logs/logins and /logs/console),
 * connections need audit "View", devices need device "View", books need
 * address-book "View". Devices and connections are scoped to the viewer's
 * fleet, same as the device list and connection log.
 */
class UserDetail extends Component
{
    use AuthorizesConsole;

    /** Rows per activity list; the full history is on the log screens. */
    public const RECENT = 25;

    public int $userId;

    public function mount(int $userId): void
    {
        $this->authorizeConsole('user', 'r');
        $this->userId = $userId;
        $this->target();
    }

    private function target(): User
    {
        return User::with(['groups', 'role'])->findOrFail($this->userId);
    }

    public function render()
    {
        $viewer = auth()->user();
        $user = $this->target();

        $canDevices = $viewer->consoleAllows('device');
        $canConnections = $viewer->consoleAllows('audit');
        $canSignIns = $viewer->consoleAllows('audit', 'rw');
        $canBooks = $viewer->consoleAllows('address_book');

        $ownedQuery = fn () => Device::query()->visibleTo($viewer)->where('user_id', $user->id);
        $clientIds = $canConnections ? $this->clientDeviceIds($user) : [];

        return view('livewire.user-detail', [
            'user' => $user,
            'canDevices' => $canDevices,
            'canConnections' => $canConnections,
            'canSignIns' => $canSignIns,
            'canBooks' => $canBooks,
            'devices' => $canDevices
                ? $ownedQuery()->with('group')->orderBy('rustdesk_id')->limit(self::RECENT)->get()
                : collect(),
            'deviceCount' => $canDevices ? $ownedQuery()->count() : 0,
            'lastSignIn' => $canSignIns
                ? LoginLog::where('user_id', $user->id)->where('successful', true)->latest()->orderByDesc('id')->first()
                : null,
            'signIns' => $canSignIns ? $this->signIns($user) : collect(),
            'actions' => $canSignIns
                ? ConsoleAudit::where('user_id', $user->id)->latest()->orderByDesc('id')->limit(self::RECENT)->get()
                : collect(),
            'incoming' => $canConnections && $canDevices
                ? $this->incoming($ownedQuery()->pluck('rustdesk_id')->all())
                : collect(),
            'outgoing' => $canConnections ? $this->outgoing($viewer, $clientIds) : collect(),
            'clientIds' => $clientIds,
            'books' => $canBooks ? $this->books($viewer, $user) : collect(),
        ]);
    }

    /**
     * Sign-ins by this account. Failed attempts carry no user id (the account
     * was not established), so they match on the username typed instead.
     */
    private function signIns(User $user)
    {
        return LoginLog::query()
            ->where(fn (Builder $q) => $q->where('user_id', $user->id)
                ->orWhere(fn (Builder $q) => $q->whereNull('user_id')->where('username', $user->username)))
            ->latest()->orderByDesc('id')
            ->limit(self::RECENT)
            ->get();
    }

    /** Sessions into devices this user owns. Ownership is the attribution. */
    private function incoming(array $rustdeskIds)
    {
        if ($rustdeskIds === []) {
            return collect();
        }

        return AuditConnection::whereIn('rustdesk_id', $rustdeskIds)
            ->latest()->orderByDesc('id')
            ->limit(self::RECENT)
            ->get();
    }

    /**
     * RustDesk ids this user has signed in on with the RustDesk client, newest
     * first: past client sign-ins plus live client tokens. Bounded.
     *
     * @return array<int, string>
     */
    private function clientDeviceIds(User $user): array
    {
        $fromLogins = LoginLog::where('user_id', $user->id)
            ->where('successful', true)
            ->whereNotNull('device_id')->where('device_id', '!=', '')
            ->latest()->limit(200)->pluck('device_id');

        $fromTokens = ClientToken::where('user_id', $user->id)
            ->whereNotNull('device_id')->where('device_id', '!=', '')
            ->pluck('device_id');

        return $fromLogins->merge($fromTokens)->unique()->take(50)->values()->all();
    }

    /**
     * Sessions started from a device this user has signed in on. The audit
     * record names the controlling device, not the person, so this is an
     * attribution by device: someone else at the same machine shows here too.
     * The view says so. Scoped to controlled devices the viewer can see.
     */
    private function outgoing(User $viewer, array $ids)
    {
        if ($ids === []) {
            return collect();
        }

        return AuditConnection::query()
            ->whereIn('from_peer', $ids)
            ->when(! $viewer->seesAllDevices(), fn (Builder $q) => $q->whereIn(
                'rustdesk_id',
                Device::query()->visibleTo($viewer)->select('rustdesk_id')
            ))
            ->latest()->orderByDesc('id')
            ->limit(self::RECENT)
            ->get();
    }

    /**
     * Books this user owns. Contents stay private: the viewer gets a link only
     * to books they could open on the address-book screen anyway.
     */
    private function books(User $viewer, User $user)
    {
        $groupIds = $viewer->is_admin ? [] : $viewer->userGroupIds();

        return AddressBook::query()
            ->where('owner_user_id', $user->id)
            ->with(['rules', 'owner'])
            ->withCount('entries')
            ->orderByDesc('is_personal')->orderBy('name')
            ->get()
            ->each(fn (AddressBook $b) => $b->setAttribute('viewer_can_open', $b->permissionFor($viewer, $groupIds) > 0));
    }
}
