<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class UserList extends Component
{
    use AuthorizesConsole, WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $role = 'all';

    #[Url(except: 'all')]
    public string $status = 'all';

    public int $perPage = 20;

    // Modal state — null means "create", otherwise id of user being edited.
    public bool $showModal = false;

    public ?int $editing = null;

    public string $username = '';

    public string $name = '';

    public string $email = '';

    /** @var array<int,int> user_group ids this user belongs to */
    public array $user_group_ids = [];

    public bool $is_admin = false;

    /**
     * Delegated role for the user being edited; null = standard user. Named
     * role_id on purpose — $role above is the list filter, not the editor.
     */
    public ?int $role_id = null;

    public bool $is_active = true;

    public string $password = '';

    /** @var array<int,int> device_group ids this user may access (non-admins) */
    public array $device_group_ids = [];

    // "Assign devices" modal — bulk-set the owner of several devices at once.
    public bool $showAssignModal = false;

    public ?int $assignUserId = null;

    public string $assignSearch = '';

    /** @var array<int,int> device ids selected in the assign modal */
    public array $assignDeviceIds = [];

    /** The Users screen needs "View"; every mutator below re-checks "Manage". */
    public function mount(): void
    {
        $this->authorizeConsole('user', 'r');
    }

    /**
     * Guard the target of a delegated user-manager's action (PLAN D4).
     *
     * A role-holder may only touch plain users: never themselves (self-edit is
     * the shortest path to granting yourself a device group or a better role —
     * My Account is where you edit yourself), never a super-admin, and never
     * another role-holder. A full administrator is unaffected.
     */
    private function guardTarget(User $target): void
    {
        if (auth()->user()?->is_admin) {
            return;
        }

        abort_if($target->id === auth()->id(), 403);
        abort_if($target->is_admin || $target->role_id !== null, 403);
    }

    /**
     * Combine what a delegated actor is allowed to grant with what the target
     * already holds beyond the actor's reach.
     *
     * The out-of-reach rows were never rendered in the editor, so saving that
     * editor must not silently revoke them: an actor can only ever add or remove
     * within their own scope.
     *
     * @param  array<int,int>  $allowed  clamped ids the form submitted
     * @param  array<int,int>  $existing  ids the target already holds
     * @param  array<int,int>  $reachable  the subset of $existing the actor may manage
     * @return array<int,int>
     */
    private function mergeGrants(array $allowed, array $existing, array $reachable): array
    {
        return array_values(array_unique(array_merge($allowed, array_diff($existing, $reachable))));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'role', 'status');
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorizeConsole('user', 'rw');

        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeConsole('user', 'rw');

        $user = User::findOrFail($id);
        $this->guardTarget($user);

        $this->resetForm();
        $this->editing = $user->id;
        $this->username = $user->username;
        $this->name = $user->name ?? '';
        $this->email = $user->email ?? '';
        $this->user_group_ids = $user->groups()->pluck('user_groups.id')->all();
        $this->is_admin = $user->is_admin;
        $this->role_id = $user->role_id;
        $this->is_active = $user->is_active;
        $this->device_group_ids = $user->deviceGroups()->pluck('device_groups.id')->all();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorizeConsole('user', 'rw');

        $validated = $this->validate([
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($this->editing)],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editing)],
            'user_group_ids' => ['array'],
            'user_group_ids.*' => [Rule::exists('user_groups', 'id')],
            'is_admin' => ['boolean'],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'is_active' => ['boolean'],
            'password' => [$this->editing ? 'nullable' : 'required', 'string', 'min:8'],
            'device_group_ids' => ['array'],
            'device_group_ids.*' => [Rule::exists('device_groups', 'id')],
        ]);

        $actor = auth()->user();
        $groupIds = array_map('intval', $validated['device_group_ids'] ?? []);
        $userGroupIds = array_map('intval', $validated['user_group_ids'] ?? []);
        unset($validated['device_group_ids'], $validated['user_group_ids']);

        // ---- Privilege-escalation guards for a delegated user-manager (D4) --
        // A role-holder with `user: rw` runs the same screen as a super-admin,
        // so everything that could hand out MORE authority than the actor holds
        // is stripped here. The blade hides these controls too, but that is
        // cosmetic — this block is the authority.
        if (! $actor?->is_admin) {
            // Never mint an administrator, and never assign or change a role:
            // roles are granted by super-admins only (see RoleList::mount).
            $validated['is_admin'] = false;
            unset($validated['role_id']);

            $existingDeviceGroupIds = [];
            $existingUserGroupIds = [];

            if ($this->editing) {
                $target = User::findOrFail($this->editing);
                $this->guardTarget($target);
                $existingDeviceGroupIds = $target->deviceGroups()->pluck('device_groups.id')
                    ->map(fn ($id) => (int) $id)->all();
                $existingUserGroupIds = $target->userGroupIds();
            }

            // Device-group grants can only ever be handed out from what the
            // actor can see themselves — otherwise "manage users" would be a
            // back door onto the whole fleet via Device::scopeVisibleTo.
            $groupIds = $this->mergeGrants(
                $this->grantableDeviceGroupIds($groupIds),
                $existingDeviceGroupIds,
                $this->grantableDeviceGroupIds($existingDeviceGroupIds),
            );

            // …and the same clamp on user groups, which carry folder grants of
            // their own: clamping only the direct grants would leave "add them
            // to Finance staff" as a one-click way round the line above.
            $userGroupIds = $this->mergeGrants(
                $this->grantableUserGroupIds($userGroupIds),
                $existingUserGroupIds,
                $this->grantableUserGroupIds($existingUserGroupIds),
            );
        }

        // A super-admin needs no role: is_admin already outranks every matrix,
        // and leaving a stale role_id behind would make consoleAllows ambiguous
        // if the flag were later removed.
        if ($validated['is_admin']) {
            $validated['role_id'] = null;
        }

        $validated['name'] = $validated['name'] ?? null;
        $validated['email'] = ($validated['email'] ?? '') !== '' ? $validated['email'] : null;

        $previousRoleId = null;

        if ($this->editing) {
            // Blank password on edit = keep the current one.
            if (($validated['password'] ?? '') === '') {
                unset($validated['password']);
            }
            $user = User::findOrFail($this->editing);
            $previousRoleId = $user->role_id;
            $user->update($validated);
        } else {
            $user = User::create($validated);
        }

        // Admins see everything, so device-group grants only apply to non-admins.
        $user->deviceGroups()->sync($validated['is_admin'] ? [] : $groupIds);

        $user->groups()->sync($userGroupIds);

        // Surface a role grant in the audit trail — it is the single most
        // consequential thing this form can do.
        $roleNote = '';
        if ($previousRoleId !== $user->role_id) {
            $roleNote = ' (role: '.($user->role_id
                ? (string) Role::whereKey($user->role_id)->value('name')
                : 'standard user').')';
        }

        ConsoleAudit::record(
            $this->editing ? 'user.update' : 'user.create',
            ($this->editing ? 'Updated' : 'Created').' user '.$user->username.$roleNote,
            'user',
            $user->username,
        );

        $this->closeModal();
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeConsole('user', 'rw');

        if ($id === auth()->id()) {
            return; // never lock yourself out
        }

        $user = User::findOrFail($id);
        $this->guardTarget($user);
        $user->update(['is_active' => ! $user->is_active]);

        // Disabling takes effect NOW, not at next login: revoke everything.
        if (! $user->is_active) {
            $this->revokeAllAccess($user);
        }

        ConsoleAudit::record(
            $user->is_active ? 'user.enable' : 'user.disable',
            ($user->is_active ? 'Enabled' : 'Disabled').' user '.$user->username,
            'user',
            $user->username,
        );
    }

    /**
     * Admin "Reset 2FA" (PLAN B6): wipe the user's TOTP secret, flags, replay
     * pointer and recovery codes so they must re-enroll. Break-glass for a
     * locked-out operator; audited.
     */
    public function resetTwoFactor(int $id): void
    {
        $this->authorizeConsole('user', 'rw');

        $user = User::findOrFail($id);
        $this->guardTarget($user);

        if (! $user->hasTwoFactorEnabled() && $user->recoveryCodes()->doesntExist() && $user->totp_secret === null) {
            return; // nothing to reset
        }

        $user->clearTwoFactor();

        ConsoleAudit::record('user.2fa-reset', 'Reset two-factor authentication for '.$user->username, 'user', $user->username);
    }

    /** Kick a user everywhere: RustDesk clients, console sessions, remember-me. */
    public function forceLogout(int $id): void
    {
        $this->authorizeConsole('user', 'rw');

        if ($id === auth()->id()) {
            return; // use the account menu to log yourself out
        }

        $user = User::findOrFail($id);
        $this->guardTarget($user);
        $this->revokeAllAccess($user);

        ConsoleAudit::record('user.force-logout', 'Forced logout of '.$user->username, 'user', $user->username);
    }

    /** Delegates to the model so reset and force-logout cannot drift apart. */
    private function revokeAllAccess(User $user): void
    {
        $user->revokeAllAccess();
    }

    public function deleteUser(int $id): void
    {
        $this->authorizeConsole('user', 'rw');

        if ($id === auth()->id()) {
            return; // cannot delete yourself
        }

        $user = User::findOrFail($id);
        $this->guardTarget($user);
        $username = $user->username;

        DB::transaction(function () use ($user): void {
            // Keep the devices, just detach them from the deleted owner.
            Device::bulkUpdateStrategyContext(
                Device::query()->where('user_id', $user->id),
                ['user_id' => null],
                auth()->user()->consoleAllows('strategy', 'rw'),
            );
            $user->delete();
        });

        ConsoleAudit::record('user.delete', 'Deleted user '.$username, 'user', $username);
    }

    // --- Assign devices (bulk owner reassignment) ----------------------------

    public function openAssign(int $userId): void
    {
        $this->authorizeConsole('user', 'rw');
        $this->guardTarget(User::findOrFail($userId));

        $this->assignUserId = $userId;
        $this->assignDeviceIds = [];
        $this->assignSearch = '';
        // Preselect the devices this user already owns — but only the ones the
        // actor can see, so the checkbox state matches what saveAssign will act
        // on and a device out of scope is never silently released.
        $this->assignDeviceIds = $this->assignableDevices()
            ->where('user_id', $userId)->pluck('id')->all();
        $this->showAssignModal = true;
    }

    /**
     * The devices this actor may re-own (PLAN D4).
     *
     * Ownership is row scope, and row scope never comes from a role: an actor
     * hands out only devices they can already see themselves. Without this the
     * modal both listed the whole fleet and re-owned any id posted to it, which
     * is a complete bypass of device-group access — reassign a hidden device to
     * an account you control, sign in as it, and the device is yours.
     */
    private function assignableDevices()
    {
        $actor = auth()->user();

        return $actor?->is_admin
            ? Device::query()
            : Device::query()->visibleTo($actor);
    }

    public function saveAssign(): void
    {
        $this->authorizeConsole('user', 'rw');

        $user = User::findOrFail($this->assignUserId);
        $this->guardTarget($user);

        $ids = $this->assignableDevices()
            ->whereIn('id', array_map('intval', $this->assignDeviceIds))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($ids, $user): void {
            // Devices to gain this owner, and this user's current devices to
            // release. Both paths share the impact-confirmation lock boundary.
            Device::bulkUpdateStrategyContext(
                Device::query()->whereIn('id', $ids ?: [0]),
                ['user_id' => $user->id],
                auth()->user()->consoleAllows('strategy', 'rw'),
            );
            Device::bulkUpdateStrategyContext(
                $this->assignableDevices()
                    ->where('user_id', $user->id)
                    ->whereNotIn('id', $ids ?: [0]),
                ['user_id' => null],
                auth()->user()->consoleAllows('strategy', 'rw'),
            );
        });

        ConsoleAudit::record(
            'user.assign-devices',
            'Assigned '.count($ids).' device(s) to '.$user->username,
            'user',
            $user->username,
        );

        $this->showAssignModal = false;
    }

    public function closeAssign(): void
    {
        $this->showAssignModal = false;
        $this->reset('assignUserId', 'assignDeviceIds', 'assignSearch');
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'username', 'name', 'email', 'user_group_ids', 'is_admin', 'role_id', 'is_active', 'password', 'device_group_ids');
        $this->resetValidation();
    }

    public function render()
    {
        $users = User::query()
            ->with(['groups', 'role'])
            ->withCount('devices')
            ->when($this->search !== '', function ($q) {
                $s = '%'.$this->search.'%';
                $q->where(function ($q) use ($s) {
                    $q->where('username', 'like', $s)
                        ->orWhere('name', 'like', $s)
                        ->orWhere('email', 'like', $s);
                });
            })
            ->when($this->role === 'admin', fn ($q) => $q->where('is_admin', true))
            ->when($this->role === 'user', fn ($q) => $q->where('is_admin', false))
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'disabled', fn ($q) => $q->where('is_active', false))
            ->orderBy('username')
            ->paginate($this->perPage);

        // Candidate devices for the assign modal (filtered by its own search).
        $assignDevices = collect();
        if ($this->showAssignModal) {
            $assignDevices = $this->assignableDevices()
                ->when($this->assignSearch !== '', function ($q) {
                    $s = '%'.$this->assignSearch.'%';
                    $q->where(fn ($q) => $q->where('rustdesk_id', 'like', $s)
                        ->orWhere('alias', 'like', $s)
                        ->orWhere('hostname', 'like', $s));
                })
                ->orderBy('rustdesk_id')
                ->limit(200)
                ->get(['id', 'rustdesk_id', 'alias', 'hostname', 'user_id']);
        }

        return view('livewire.user-list', [
            'users' => $users,
            'roles' => Role::orderBy('name')->get(),
            // The pickers offer only what save() would actually accept from this
            // actor, so a delegated user-manager is never shown a grant that
            // would be silently dropped (or a folder name they cannot see).
            'userGroups' => $this->grantableUserGroups(),
            'deviceGroups' => $this->grantableDeviceGroups(),
            'assignDevices' => $assignDevices,
        ]);
    }
}
