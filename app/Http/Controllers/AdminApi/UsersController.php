<?php

namespace App\Http\Controllers\AdminApi;

use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UsersController extends AdminApiController
{
    /** GET /api/v1/users — fuzzy name search + group filter. */
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with('groups')
            ->when($request->filled('name'), function ($q) use ($request) {
                $s = '%'.$request->query('name').'%';
                $q->where(fn ($q) => $q->where('username', 'like', $s)
                    ->orWhere('name', 'like', $s)
                    ->orWhere('email', 'like', $s));
            })
            ->when($request->filled('group'), function ($q) use ($request) {
                $group = $request->query('group');
                $q->whereHas('groups', fn ($g) => is_numeric($group)
                    ? $g->where('user_groups.id', (int) $group)
                    : $g->where('user_groups.name', $group));
            })
            ->when($request->filled('status'), fn ($q) => $q
                ->where('is_active', $request->query('status') === 'active'))
            ->orderBy('username')
            ->paginate($this->perPage($request));

        return $this->paginated($users, fn (User $u) => $this->serialize($u));
    }

    /** GET /api/v1/users/{user}. */
    public function show(User $user): JsonResponse
    {
        return $this->ok($this->serialize($user->load('groups')));
    }

    /** POST /api/v1/users. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'is_active' => ['boolean'],
            'note' => ['nullable', 'string'],
        ]);

        // Admin promotion is deliberately NOT exposed to the API — a token
        // scoped to "manage users" must not be able to escalate to minting
        // console admins. Grant admin in the console UI only. The same applies
        // to delegated roles (PLAN D4): a role is a privilege grant, so the
        // automation API can never mint an account that already holds one.
        $data['is_admin'] = false;
        $data['role_id'] = null;

        $user = User::create($data);

        ConsoleAudit::record('user.create', 'Created user '.$user->username.' (API)', 'user', $user->username);

        return $this->created($this->serialize($user), 'User created.');
    }

    /** POST /api/v1/users/{user}/enable. */
    public function enable(User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

        ConsoleAudit::record('user.enable', 'Enabled user '.$user->username.' (API)', 'user', $user->username);

        return $this->ok($this->serialize($user), 'User enabled.');
    }

    /** POST /api/v1/users/{user}/disable. */
    public function disable(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return $this->fail('You cannot disable the token owner account.', 422);
        }

        $user->update(['is_active' => false]);
        $this->revokeAllAccess($user);

        ConsoleAudit::record('user.disable', 'Disabled user '.$user->username.' (API)', 'user', $user->username);

        return $this->ok($this->serialize($user), 'User disabled.');
    }

    /** POST /api/v1/users/{user}/force-logout. */
    public function forceLogout(User $user): JsonResponse
    {
        $this->revokeAllAccess($user);

        ConsoleAudit::record('user.force-logout', 'Forced logout of '.$user->username.' (API)', 'user', $user->username);

        return $this->ok(null, 'User logged out everywhere.');
    }

    /** DELETE /api/v1/users/{user}. */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return $this->fail('You cannot delete the token owner account.', 422);
        }

        $username = $user->username;
        try {
            DB::transaction(function () use ($request, $user): void {
                Device::bulkUpdateStrategyContext(
                    Device::query()->where('user_id', $user->id),
                    ['user_id' => null],
                    $this->token($request)->allows('strategy', 'rw'),
                );
                $user->delete();
            });
        } catch (AuthorizationException) {
            return $this->fail("Token lacks 'rw' permission on 'strategy'.", 403);
        }

        ConsoleAudit::record('user.delete', 'Deleted user '.$username.' (API)', 'user', $username);

        return $this->ok(null, 'User deleted.');
    }

    /**
     * Kick a user everywhere. Must stay identical to the console's
     * UserList::revokeAllAccess — force-logout and disable are the same action
     * whether they are clicked or scripted, and a stolen laptop is the case that
     * gets scripted.
     */
    private function revokeAllAccess(User $user): void
    {
        $user->clientTokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        // Trusted browsers skip the emailed sign-in code (PLAN D1), so leaving
        // them behind would let the same browser sign straight back in.
        DB::table('trusted_devices')->where('user_id', $user->id)->delete();
        $user->setRememberToken(Str::random(60));
        $user->save();
    }

    private function serialize(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'note' => $user->note,
            'is_admin' => (bool) $user->is_admin,
            'is_active' => (bool) $user->is_active,
            'groups' => $user->relationLoaded('groups')
                ? $user->groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->all()
                : [],
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
