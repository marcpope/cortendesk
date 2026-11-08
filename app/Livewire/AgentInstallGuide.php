<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Livewire\Concerns\CreatesEnrollmentTokens;
use App\Models\Agent;
use App\Models\AgentEnrollmentToken;
use App\Support\AgentInstall;
use App\Support\Monitoring;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Add agent: pick or create an enrollment token, install on each OS (one
 * command or a manual download), then watch for the machine to report. Also the
 * onboarding screen's body while no agents exist (`embedded`).
 */
class AgentInstallGuide extends Component
{
    use AuthorizesConsole, CreatesEnrollmentTokens;

    public const OSES = ['windows', 'macos', 'linux'];

    public bool $embedded = false;

    #[Url(as: 'os', except: 'windows')]
    public string $os = 'windows';

    /** The enrollment token the commands are filled with (id, not the secret). */
    #[Url(as: 'token', except: 0)]
    public int $tokenId = 0;

    /** The new-token form is open. */
    public bool $creating = false;

    /** Agents that enrolled after this moment are "new" on step 3. */
    public string $since = '';

    public function boot(): void
    {
        abort_unless(Monitoring::enabled(), 404);
    }

    public function mount(bool $embedded = false): void
    {
        $this->authorizeConsole('monitoring', 'rw');
        $this->embedded = $embedded;
        $this->since = now()->toIso8601String();
        $this->name = __('monitoring.first_token_name');
        if (! in_array($this->os, self::OSES, true)) {
            $this->os = 'windows';
        }
        // Start on the newest usable token, so the command is ready to copy.
        $tokens = AgentEnrollmentToken::usableFor(auth()->user());
        if (! $tokens->contains('id', $this->tokenId)) {
            $this->tokenId = (int) $tokens->first()?->id;
        }
        $this->creating = $tokens->isEmpty();
    }

    public function setOs(string $os): void
    {
        $this->os = in_array($os, self::OSES, true) ? $os : 'windows';
    }

    public function create(): void
    {
        if ($created = $this->createToken()) {
            $this->tokenId = $created[0]->id;
            $this->creating = false;
            $this->name = __('monitoring.first_token_name');
        }
    }

    public function startCreate(): void
    {
        $this->resetErrorBag();
        $this->creating = true;
    }

    public function cancelCreate(): void
    {
        $this->resetErrorBag();
        $this->creating = false;
    }

    public function render()
    {
        $tokens = AgentEnrollmentToken::usableFor(auth()->user());
        $selected = $tokens->firstWhere('id', $this->tokenId);
        $plain = $selected?->plain() ?? '<token>';
        $server = AgentInstall::serverUrl();
        $files = AgentInstall::files();
        $published = collect($files)->contains(fn ($f) => strlen($f['sha256']) === 64);

        return view('livewire.agent-install-guide', [
            'server' => $server,
            'serverWorks' => Monitoring::agentUrlWorks($server),
            'serverPlain' => Monitoring::agentUrlIsPlain($server),
            'groups' => MonitoringDashboard::groupsFor(auth()->user()),
            'tokens' => $tokens,
            'selected' => $selected,
            'commands' => AgentInstall::commands($plain),
            'manual' => AgentInstall::manualCommands($plain),
            'downloads' => $published ? AgentInstall::downloads() : [],
            'version' => AgentInstall::version(),
            'canSettings' => $this->consoleAllows('setting', 'rw'),
            'newAgents' => Agent::query()->visibleTo(auth()->user())
                ->where('created_at', '>=', Carbon::parse($this->since))
                ->latest('id')->limit(10)->get(),
        ]);
    }
}
