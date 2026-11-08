<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Livewire\Concerns\CreatesEnrollmentTokens;
use App\Models\AgentEnrollmentToken;
use App\Models\ConsoleAudit;
use App\Support\Monitoring;
use Livewire\Component;

/**
 * Enrollment tokens for the CortenDesk Agent. A new token opens Add agent,
 * which shows the install commands for it.
 */
class AgentEnrollmentManager extends Component
{
    use AuthorizesConsole, CreatesEnrollmentTokens;

    public function boot(): void
    {
        abort_unless(Monitoring::enabled(), 404);
    }

    public function mount(): void
    {
        $this->authorizeConsole('monitoring', 'rw');
    }

    public function create(): void
    {
        if ($created = $this->createToken()) {
            $this->redirectRoute('monitoring.add', ['token' => $created[0]->id]);
        }
    }

    public function revoke(int $id): void
    {
        $this->authorizeConsole('monitoring', 'rw');

        $user = auth()->user();
        $token = AgentEnrollmentToken::query()
            ->whereNull('revoked_at')
            ->when(! $user->seesAllDevices(), fn ($q) => $q->whereIn('device_group_id', $user->accessibleDeviceGroupIds() ?: [0]))
            ->find($id);
        if (! $token) {
            return;
        }

        $token->forceFill(['revoked_at' => now()])->save();
        ConsoleAudit::record('agent.token-revoke', 'Revoked agent enrollment token '.$token->name, 'agent_enrollment_token', (string) $token->id);
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.agent-enrollment-manager', [
            'tokens' => AgentEnrollmentToken::query()
                ->when(! $user->seesAllDevices(), fn ($q) => $q->whereIn('device_group_id', $user->accessibleDeviceGroupIds() ?: [0]))
                ->with(['group:id,name', 'creator:id,username'])
                ->withCount('agents')
                ->orderByRaw('revoked_at is not null')
                ->latest('id')
                ->get(),
            'groups' => MonitoringDashboard::groupsFor($user),
        ]);
    }
}
