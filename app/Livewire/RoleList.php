<?php

namespace App\Livewire;

use App\Models\ConsoleAudit;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Roles console screen (PLAN D4): named permission matrices over the console
 * areas, assigned to users from the Users editor.
 *
 * Super-admin only, deliberately. A delegated admin who could edit roles could
 * simply add `setting: rw` to their own role, which would make every other
 * guard in D4 decorative — so this is the one screen `is_admin` still owns
 * outright, at the route AND in the component (the Livewire update endpoint is
 * reachable without passing through the route).
 */
class RoleList extends Component
{
    public bool $showModal = false;

    /** null = creating, otherwise the role id being edited. */
    public ?int $editing = null;

    public string $name = '';

    public string $description = '';

    /** @var array<string,string> resource => none|r|rw */
    public array $permissions = [];

    public bool $require_two_factor = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
        $this->resetForm();
    }

    public function create(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $role = Role::findOrFail($id);

        $this->resetForm();
        $this->editing = $role->id;
        $this->name = $role->name;
        $this->description = (string) $role->description;
        $this->require_two_factor = (bool) $role->require_two_factor;
        // Normalise on load too, so a role stored before a resource existed
        // shows that row as "None" rather than as a blank radio group.
        $this->permissions = Role::normalizePermissions((array) $role->permissions);
        $this->showModal = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($this->editing)],
            'description' => ['nullable', 'string', 'max:255'],
            'require_two_factor' => ['boolean'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Permissions::LEVELS)],
        ]);

        $attributes = [
            'name' => $validated['name'],
            'description' => ($validated['description'] ?? '') !== '' ? $validated['description'] : null,
            'require_two_factor' => (bool) ($validated['require_two_factor'] ?? false),
            'permissions' => Role::normalizePermissions($this->permissions),
        ];

        if ($this->editing) {
            $role = Role::findOrFail($this->editing);
            $role->update($attributes);
        } else {
            $role = Role::create($attributes);
        }

        ConsoleAudit::record(
            $this->editing ? 'role.update' : 'role.create',
            ($this->editing ? 'Updated' : 'Created').' role '.$role->name.' ['.$this->summarize($role).']',
            'role',
            $role->name,
        );

        $this->closeModal();
    }

    public function deleteRole(int $id): void
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $role = Role::findOrFail($id);
        $name = $role->name;
        $holders = $role->users()->count();

        // users.role_id is nullOnDelete, so every holder silently reverts to
        // the standard-user baseline — which is what the confirmation promises.
        $role->delete();

        ConsoleAudit::record(
            'role.delete',
            'Deleted role '.$name.' ('.$holders.' user(s) reverted to standard access)',
            'role',
            $name,
        );
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    /** Compact "device:rw, audit:r" summary for the audit trail. */
    private function summarize(Role $role): string
    {
        $granted = collect(Role::normalizePermissions((array) $role->permissions))
            ->filter(fn ($level) => $level !== 'none')
            ->map(fn ($level, $resource) => $resource.':'.$level)
            ->values();

        return $granted->isEmpty() ? 'no permissions' : $granted->join(', ');
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'name', 'description', 'require_two_factor');
        // A new role starts from the standard-user baseline: the least
        // surprising starting point, and never more than the user already had.
        $this->permissions = Role::normalizePermissions(Permissions::LEGACY_USER);
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.role-list', [
            'roles' => Role::withCount('users')->orderBy('name')->get(),
            'resources' => Permissions::CONSOLE_RESOURCES,
            'resourceLabels' => Permissions::resourceLabels(),
            'resourceHints' => Permissions::resourceHints(),
            'levels' => Permissions::LEVELS,
            'levelLabels' => Permissions::levelLabels(),
        ]);
    }
}
