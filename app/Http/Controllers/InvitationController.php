<?php

namespace App\Http\Controllers;

use App\Models\ConsoleAudit;
use App\Models\DeviceGroup;
use App\Models\Invitation;
use App\Models\LoginLog;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Invitation acceptance (PLAN D1).
 *
 * THE LOAD-BEARING RULE: nothing about the new account's PRIVILEGES is ever
 * read from the request. is_admin and both group lists come from the
 * invitation row and nowhere else, so a recipient who posts `is_admin=1`
 * alongside their password still gets exactly what the inviting admin chose.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $invitation = $this->resolve($token);

        return view('auth.invite', ['invitation' => $invitation, 'token' => $token]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->resolve($token);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'same:password_confirmation'],
            'password_confirmation' => ['required', 'string'],
        ], [
            'password.same' => __('messages.passwords_mismatch'),
            'password_confirmation.required' => __('messages.confirm_your_password'),
        ]);

        // Re-check uniqueness at redemption, not just at invite time: the
        // address or username may have been claimed while the invite sat in an
        // inbox, and the unique indexes would otherwise surface as a 500.
        if (User::where('username', $invitation->username)->exists()
            || User::where('email', $invitation->email)->exists()) {
            return back()->withErrors([
                'password' => __('messages.account_exists'),
            ]);
        }

        $user = null;

        DB::transaction(function () use ($invitation, $validated, &$user) {
            // Claim the invitation FIRST with a conditional update. The write
            // only lands while the row is still unaccepted and unexpired, so a
            // double-submit cannot produce two accounts (same pattern as the
            // recovery-code burn in consumeRecoveryCode).
            $claimed = Invitation::whereKey($invitation->getKey())
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->update(['accepted_at' => now()]);

            if ($claimed === 0) {
                abort(410, __('messages.invite_used'));
            }

            $user = User::create([
                'username' => $invitation->username,
                'name' => ($validated['name'] ?? '') !== '' ? $validated['name'] : $invitation->name,
                'email' => $invitation->email,
                'password' => $validated['password'],
                // From the invitation row — never from the request.
                'is_admin' => $invitation->is_admin,
                'is_active' => true,
            ]);

            // Re-intersect with what still exists: a group deleted since the
            // invite was sent must not resurrect as a dangling pivot row.
            $userGroupIds = UserGroup::whereIn('id', $invitation->user_group_ids ?? [])->pluck('id')->all();
            $deviceGroupIds = $invitation->is_admin
                ? []
                : DeviceGroup::whereIn('id', $invitation->device_group_ids ?? [])->pluck('id')->all();

            $user->groups()->sync($userGroupIds);
            $user->deviceGroups()->sync($deviceGroupIds);

            Invitation::whereKey($invitation->getKey())->update(['accepted_user_id' => $user->id]);
        });

        Auth::login($user);
        $request->session()->regenerate();

        LoginLog::create([
            'user_id' => $user->id,
            'username' => $user->username,
            'client' => 'web',
            'ip' => $request->ip(),
            'successful' => true,
            'note' => 'invitation',
        ]);

        // Must follow Auth::login: ConsoleAudit::record no-ops when nobody is
        // authenticated, and the new user is the actor here.
        ConsoleAudit::record(
            'user.invite-accept',
            'Accepted invitation and created user '.$user->username,
            'user',
            $user->username,
        );

        return redirect()->route('overview');
    }

    /**
     * Resolve a live invitation whose issuing authority still stands.
     *
     * An invitation must not outlive the authority that issued it, so a deleted,
     * disabled or demoted inviter voids it. The test is the authority the
     * invitation actually needs, not the admin flag: PLAN D4 lets a delegated
     * user-manager (`user: rw`) onboard people, and requiring is_admin here made
     * every invitation they sent unredeemable. An invitation that grants
     * ADMINISTRATOR still requires its inviter to be one — that privilege can
     * only ever come from a super-admin.
     */
    private function resolve(string $token): Invitation
    {
        $invitation = Invitation::findValid($token);

        abort_if($invitation === null, 404);

        $inviter = $invitation->inviter;

        $stillAuthorised = $inviter
            && $inviter->is_active
            && $inviter->consoleAllows('user', 'rw')
            && (! $invitation->is_admin || $inviter->is_admin);

        if (! $stillAuthorised) {
            abort(403, __('messages.invite_invalid'));
        }

        return $invitation;
    }
}
