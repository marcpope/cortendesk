<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Mail\UserInvitation;
use App\Models\ConsoleAudit;
use App\Models\Invitation;
use App\Services\MailSettings;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Users → Invitations (PLAN D1): invite by email, watch what is outstanding,
 * resend or revoke. The accept URL is always shown for copy/paste so a console
 * whose relay is broken (or unconfigured) can still onboard people.
 */
class InvitationManager extends Component
{
    use AuthorizesConsole;

    public bool $showModal = false;

    public string $email = '';

    public string $username = '';

    public string $name = '';

    public bool $is_admin = false;

    /** @var array<int,int> */
    public array $user_group_ids = [];

    /** @var array<int,int> */
    public array $device_group_ids = [];

    /** Accept URL surfaced once after create/resend (the token is unrecoverable). */
    public ?string $inviteUrl = null;

    public string $inviteFor = '';

    /** Whether the last create/resend actually reached the relay. */
    public bool $mailSent = false;

    public function mount(): void
    {
        // /livewire/update is reachable directly, so the component guards itself
        // rather than trusting the route group it happens to be rendered under.
        // Inviting creates a user, so it needs "Manage" on users (PLAN D4).
        $this->authorizeConsole('user', 'rw');
    }

    public function create(): void
    {
        $this->authorizeConsole('user', 'rw');

        $this->resetForm();
        $this->inviteUrl = null;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorizeConsole('user', 'rw');

        $validated = $this->validate([
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email'),
                // A second live invite for the same address would race to the
                // unique index at redemption; reject it here instead.
                Rule::unique('invitations', 'email')->where($this->liveInvitation(...)),
            ],
            'username' => [
                'required', 'string', 'max:255',
                Rule::unique('users', 'username'),
                Rule::unique('invitations', 'username')->where($this->liveInvitation(...)),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'is_admin' => ['boolean'],
            'user_group_ids' => ['array'],
            'user_group_ids.*' => [Rule::exists('user_groups', 'id')],
            'device_group_ids' => ['array'],
            'device_group_ids.*' => [Rule::exists('device_groups', 'id')],
        ], [
            'email.unique' => __('messages.invite_email_taken'),
            'username.unique' => __('messages.invite_username_taken'),
        ]);

        // Same escalation guards as UserList::save (PLAN D4): a delegated
        // user-manager may not invite an administrator, and may not pre-grant a
        // device group they cannot see themselves — an invitation is just a
        // deferred user creation and must not be a way around either rule.
        $actor = auth()->user();
        if (! $actor?->is_admin) {
            $validated['is_admin'] = false;
            $validated['device_group_ids'] = $this->grantableDeviceGroupIds($validated['device_group_ids'] ?? []);
            // User groups carry folder grants of their own, so they need the
            // same clamp — otherwise "invite them into Finance staff" hands out
            // exactly what the line above refuses.
            $validated['user_group_ids'] = $this->grantableUserGroupIds($validated['user_group_ids'] ?? []);
        }

        [$invitation, $plain] = Invitation::issue($validated, $actor);

        $this->deliver($invitation, $plain);

        ConsoleAudit::record(
            'user.invite',
            'Invited '.$invitation->email.' as '.$invitation->username.($invitation->is_admin ? ' (administrator)' : ''),
            'invitation',
            (string) $invitation->id,
        );

        $this->showModal = false;
        $this->resetForm();
    }

    /**
     * Re-send an invitation.
     *
     * The old token cannot be recovered (only its hash is stored), so this
     * mints a new one and pushes the expiry out — the previous link stops
     * working the moment this runs.
     */
    public function resend(int $id): void
    {
        $this->authorizeConsole('user', 'rw');

        $invitation = Invitation::whereKey($id)->whereNull('accepted_at')->first();

        if (! $invitation) {
            return;
        }

        $this->guardInvitation($invitation);

        $plain = $invitation->rotate();
        $this->deliver($invitation->refresh(), $plain);

        ConsoleAudit::record(
            'user.invite-resend',
            'Re-sent the invitation for '.$invitation->email,
            'invitation',
            (string) $invitation->id,
        );
    }

    public function revoke(int $id): void
    {
        $this->authorizeConsole('user', 'rw');

        $invitation = Invitation::whereKey($id)->whereNull('accepted_at')->first();

        if (! $invitation) {
            return;
        }

        $this->guardInvitation($invitation);

        $email = $invitation->email;
        $invitation->delete();

        if ($this->inviteFor === $email) {
            $this->inviteUrl = null;
            $this->inviteFor = '';
        }

        ConsoleAudit::record('user.invite-revoke', 'Revoked the invitation for '.$email, 'invitation', (string) $id);
    }

    public function dismissLink(): void
    {
        $this->inviteUrl = null;
        $this->inviteFor = '';
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    /**
     * Guard an EXISTING invitation against a delegated user-manager (PLAN D4).
     *
     * resend() re-mints the accept token and puts the URL on the actor's own
     * screen, so being allowed to resend an invitation is equivalent to being
     * allowed to become the account it creates: an administrator invitation
     * issued by a super-admin would otherwise be a one-click promotion. The test
     * is therefore "could this actor have created this invitation themselves?",
     * which is exactly what save() clamps to. revoke() gets the same guard so
     * one delegate cannot quietly cancel an administrator's onboarding.
     */
    private function guardInvitation(Invitation $invitation): void
    {
        abort_unless($this->mayManage($invitation), 403);
    }

    /** @see guardInvitation — the same question, asked without aborting. */
    private function mayManage(Invitation $invitation): bool
    {
        if (auth()->user()?->is_admin) {
            return true;
        }

        if ($invitation->is_admin) {
            return false;
        }

        $deviceGroupIds = array_map('intval', $invitation->device_group_ids ?? []);
        if (array_diff($deviceGroupIds, $this->grantableDeviceGroupIds($deviceGroupIds)) !== []) {
            return false;
        }

        $userGroupIds = array_map('intval', $invitation->user_group_ids ?? []);

        return array_diff($userGroupIds, $this->grantableUserGroupIds($userGroupIds)) === [];
    }

    /** Narrow a uniqueness check to invitations that can still be accepted. */
    private function liveInvitation($query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /** Mail the link if we can, and surface it either way. */
    private function deliver(Invitation $invitation, string $plain): void
    {
        $url = route('invite.show', $plain);

        $this->mailSent = app(MailSettings::class)->send(
            new UserInvitation($invitation, $url, auth()->user()?->displayName() ?? __('messages.an_administrator')),
            $invitation->email,
        );

        $this->inviteUrl = $url;
        $this->inviteFor = $invitation->email;
    }

    private function resetForm(): void
    {
        $this->reset('email', 'username', 'name', 'is_admin', 'user_group_ids', 'device_group_ids');
        $this->resetValidation();
    }

    public function render()
    {
        $invitations = Invitation::with('inviter')
            ->whereNull('accepted_at')
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.invitation-manager', [
            'invitations' => $invitations,
            // Rows a delegated user-manager may not resend or revoke still show
            // (so "that address already has a pending invitation" makes sense),
            // but without the actions — the guard in resend()/revoke() is the
            // authority, this only keeps the screen honest.
            'manageableIds' => $invitations->filter($this->mayManage(...))->pluck('id')->all(),
            'userGroups' => $this->grantableUserGroups(),
            'deviceGroups' => $this->grantableDeviceGroups(),
            'mailEnabled' => app(MailSettings::class)->isEnabled(),
        ]);
    }
}
