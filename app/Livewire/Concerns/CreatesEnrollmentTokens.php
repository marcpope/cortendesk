<?php

namespace App\Livewire\Concerns;

use App\Livewire\MonitoringDashboard;
use App\Models\AgentEnrollmentToken;
use App\Models\ConsoleAudit;

/**
 * The enrollment token form, shared by the Enrollment tab and the Add agent
 * guide. Only the token's hash is stored; the plain value is returned once.
 */
trait CreatesEnrollmentTokens
{
    public string $name = '';

    public int $groupId = 0;

    public ?int $expiresDays = null;

    public ?int $maxUses = null;

    /** @return array{0: AgentEnrollmentToken, 1: string}|null */
    protected function createToken(): ?array
    {
        $this->authorizeConsole('monitoring', 'rw');

        $this->validate([
            'name' => 'required|string|max:100',
            'groupId' => 'integer|min:0',
            'expiresDays' => 'nullable|integer|min:1|max:3650',
            'maxUses' => 'nullable|integer|min:1|max:100000',
        ]);

        $user = auth()->user();
        $groupId = $this->groupId > 0 ? $this->groupId : null;
        // An agent with no group is visible to full admins only, so anyone
        // limited to groups must pick one or they could never see the agent.
        if ($groupId === null && ! $user->seesAllDevices()) {
            $this->addError('groupId', __('monitoring.token_group_required'));

            return null;
        }
        if ($groupId !== null && ! MonitoringDashboard::groupsFor($user)->contains('id', $groupId)) {
            $this->addError('groupId', __('messages.group_gone'));

            return null;
        }

        [$token, $plain] = AgentEnrollmentToken::issue(
            trim($this->name),
            $groupId,
            $user,
            $this->expiresDays ? now()->addDays($this->expiresDays) : null,
            $this->maxUses ?: null,
        );

        ConsoleAudit::record('agent.token-create', 'Created agent enrollment token '.$token->name, 'agent_enrollment_token', (string) $token->id);

        $this->reset('expiresDays', 'maxUses');
        $this->name = '';

        return [$token, $plain];
    }
}
