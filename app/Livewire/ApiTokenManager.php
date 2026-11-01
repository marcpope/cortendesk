<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\ApiToken;
use App\Models\ConsoleAudit;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Settings → API Tokens: create scoped admin-api tokens (permission matrix),
 * list them with last-used, and revoke. The plaintext is shown exactly once.
 */
class ApiTokenManager extends Component
{
    use AuthorizesConsole;

    public bool $showModal = false;

    public string $name = '';

    /** Optional lifetime in days; blank = never expires. */
    public ?int $expiresDays = null;

    /** @var array<string,string> resource => none|r|rw */
    public array $permissions = [];

    /** Plaintext token surfaced once immediately after creation. */
    public ?string $plaintext = null;

    public function mount(): void
    {
        $this->authorizeConsole('token', 'r');
        $this->resetForm();
    }

    public function create(): void
    {
        $this->authorizeConsole('token', 'rw');

        $this->resetForm();
        $this->plaintext = null;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorizeConsole('token', 'rw');

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'expiresDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(ApiToken::LEVELS)],
        ]);

        // Reject an all-"none" token: it could do nothing.
        if (! collect($this->permissions)->contains(fn ($l) => $l !== 'none')) {
            $this->addError('permissions', __('messages.grant_one_permission'));

            return;
        }

        [$token, $plain] = ApiToken::issue(
            auth()->user(),
            $this->name,
            $this->permissions,
            $this->expiresDays ? now()->addDays($this->expiresDays) : null,
        );

        ConsoleAudit::record('api-token.create', 'Created API token '.$token->name, 'api_token', (string) $token->id);

        $this->plaintext = $plain;
        $this->showModal = false;
        $this->reset('name', 'expiresDays');
        $this->resetPermissions();
    }

    public function revoke(int $id): void
    {
        $this->authorizeConsole('token', 'rw');

        $token = ApiToken::find($id);
        if (! $token) {
            return;
        }

        $name = $token->name;
        $token->delete();

        ConsoleAudit::record('api-token.revoke', 'Revoked API token '.$name, 'api_token', (string) $id);
    }

    public function dismissPlaintext(): void
    {
        $this->plaintext = null;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('name', 'expiresDays');
        $this->resetPermissions();
        $this->resetValidation();
    }

    private function resetPermissions(): void
    {
        $this->permissions = array_fill_keys(ApiToken::RESOURCES, 'none');
    }

    public function render()
    {
        return view('livewire.api-token-manager', [
            'tokens' => ApiToken::with('user')->orderByDesc('created_at')->get(),
            'resources' => ApiToken::RESOURCES,
            'levels' => ApiToken::LEVELS,
        ]);
    }
}
