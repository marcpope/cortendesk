<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\AddressBook;
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

    /** Shared address books the token may only add entries to (#151). */
    public array $addBooks = [];

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
            'addBooks' => ['array'],
            'addBooks.*' => ['integer'],
        ]);

        // Reject a token that could do nothing.
        if (! collect($this->permissions)->contains(fn ($l) => $l !== 'none')
            && ApiToken::clampAddBooks(auth()->user(), $this->addBooks) === []) {
            $this->addError('permissions', __('messages.grant_one_permission'));

            return;
        }

        [$token, $plain] = ApiToken::issue(
            auth()->user(),
            $this->name,
            $this->permissions,
            $this->expiresDays ? now()->addDays($this->expiresDays) : null,
            $this->addBooks,
        );

        ConsoleAudit::record('api-token.create', 'Created API token '.$token->name, 'api_token', (string) $token->id);

        $this->plaintext = $plain;
        $this->showModal = false;
        $this->reset('name', 'expiresDays', 'addBooks');
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
        $this->reset('name', 'expiresDays', 'addBooks');
        $this->resetPermissions();
        $this->resetValidation();
    }

    private function resetPermissions(): void
    {
        $this->permissions = array_fill_keys(ApiToken::RESOURCES, 'none');
    }

    public function render()
    {
        $tokens = ApiToken::with('user')->orderByDesc('created_at')->get();

        return view('livewire.api-token-manager', [
            'tokens' => $tokens,
            'resources' => ApiToken::RESOURCES,
            'levels' => ApiToken::LEVELS,
            // Only shared books, and only for someone who may manage them (ApiToken::clampAddBooks).
            'sharedBooks' => $this->showModal && auth()->user()->consoleAllows('address_book', 'rw')
                ? AddressBook::query()->where('is_personal', false)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'bookNames' => AddressBook::query()
                ->whereIn('id', $tokens->flatMap(fn (ApiToken $t) => $t->address_book_add ?? [])->unique()->all())
                ->pluck('name', 'id'),
        ]);
    }
}
