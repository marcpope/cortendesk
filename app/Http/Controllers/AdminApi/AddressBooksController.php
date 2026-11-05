<?php

namespace App\Http\Controllers\AdminApi;

use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\AddressBookRule;
use App\Models\ConsoleAudit;
use App\Livewire\AddressBookManager;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AddressBooksController extends AdminApiController
{
    // ---- Address books -----------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $books = AddressBook::query()
            ->with('owner')
            ->withCount(['entries', 'tags', 'rules'])
            ->when($request->filled('name'), fn ($q) => $q
                ->where('name', 'like', '%'.$request->query('name').'%'))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->paginated($books, fn (AddressBook $b) => $this->serializeBook($b));
    }

    public function show(AddressBook $addressBook): JsonResponse
    {
        return $this->ok($this->serializeBook(
            $addressBook->load('owner')->loadCount(['entries', 'tags', 'rules'])
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'is_personal' => ['boolean'],
            'note' => ['nullable', 'string'],
        ]);

        // Books need an owner; default to the token's creator (a shared book the
        // operator owns) when the caller does not name one.
        $data['owner_user_id'] ??= $request->user()->id;

        $book = AddressBook::create($data);

        ConsoleAudit::record('address-book.create', 'Created address book '.$book->name.' (API)', 'address_book', $book->guid);

        return $this->created($this->serializeBook($book), 'Address book created.');
    }

    public function update(Request $request, AddressBook $addressBook): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'note' => ['nullable', 'string'],
        ]);

        $addressBook->update($data);

        ConsoleAudit::record('address-book.update', 'Updated address book '.$addressBook->name.' (API)', 'address_book', $addressBook->guid);

        return $this->ok($this->serializeBook($addressBook), 'Address book updated.');
    }

    public function destroy(AddressBook $addressBook): JsonResponse
    {
        $guid = $addressBook->guid;
        $addressBook->entries()->delete();
        $addressBook->tags()->delete();
        $addressBook->rules()->delete();
        $addressBook->delete();

        ConsoleAudit::record('address-book.delete', 'Deleted address book '.$addressBook->name.' (API)', 'address_book', $guid);

        return $this->ok(null, 'Address book deleted.');
    }

    // ---- Peers (entries) ---------------------------------------------------

    public function peers(AddressBook $addressBook): JsonResponse
    {
        $peers = $addressBook->entries()->orderBy('rustdesk_id')->get();

        return $this->ok($peers->map(fn (AddressBookEntry $e) => $this->serializePeer($e))->all());
    }

    /**
     * Add an entry. A token with only the add grant for this book (#151) is
     * for rollout scripts, whose credential anyone on the machine can read:
     * it gets back the new id and nothing else, and an ID already in the book
     * is a 409 so a script that runs twice adds nothing.
     */
    public function storePeer(Request $request, AddressBook $addressBook): JsonResponse
    {
        $addOnly = (bool) $request->attributes->get('api_token_add_only');
        $data = $this->validatePeer($request);
        $password = $this->passwordUpdate($request, $addressBook, $data);
        $data['address_book_id'] = $addressBook->id;

        if ($addOnly && $addressBook->entries()->where('rustdesk_id', $data['rustdesk_id'])->exists()) {
            return response()->json(['code' => 409, 'data' => null, 'message' => 'Peer already in this address book.'], 409);
        }

        $peer = AddressBookEntry::create($data + $password);

        ConsoleAudit::record('address-book.peer-add', 'Added peer '.$peer->rustdesk_id.' to '.$addressBook->name.(! empty($password['password_enc']) ? ', password set' : '').' (API'.($addOnly ? ', add-only token' : '').')', 'address_book', $addressBook->guid);

        return $this->created($addOnly ? ['id' => $peer->id] : $this->serializePeer($peer), 'Peer added.');
    }

    public function updatePeer(Request $request, AddressBook $addressBook, AddressBookEntry $peer): JsonResponse
    {
        abort_unless($peer->address_book_id === $addressBook->id, 404);

        $data = $this->validatePeer($request, true);
        $password = $this->passwordUpdate($request, $addressBook, $data);
        $had = $peer->password_enc !== null;
        $peer->update($data + $password);

        $note = match (true) {
            $password === [] => '',
            $password['password_enc'] === null => $had ? ', password cleared' : '',
            default => $had ? ', password changed' : ', password set',
        };
        ConsoleAudit::record('address-book.peer-update', 'Updated peer '.$peer->rustdesk_id.$note.' (API)', 'address_book', $addressBook->guid);

        return $this->ok($this->serializePeer($peer), 'Peer updated.');
    }

    public function destroyPeer(AddressBook $addressBook, AddressBookEntry $peer): JsonResponse
    {
        abort_unless($peer->address_book_id === $addressBook->id, 404);
        $rid = $peer->rustdesk_id;
        $peer->delete();

        ConsoleAudit::record('address-book.peer-delete', 'Deleted peer '.$rid.' (API)', 'address_book', $addressBook->guid);

        return $this->ok(null, 'Peer deleted.');
    }

    // ---- Tags --------------------------------------------------------------

    public function tags(AddressBook $addressBook): JsonResponse
    {
        return $this->ok($addressBook->tags()->orderBy('name')->get()
            ->map(fn (Tag $t) => $this->serializeTag($t))->all());
    }

    public function storeTag(Request $request, AddressBook $addressBook): JsonResponse
    {
        $data = $this->tagColor($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]));
        $data['address_book_id'] = $addressBook->id;

        $tag = Tag::create($data);

        ConsoleAudit::record('address-book.tag-add', 'Added tag '.$tag->name.' (API)', 'address_book', $addressBook->guid);

        return $this->created($this->serializeTag($tag), 'Tag added.');
    }

    public function updateTag(Request $request, AddressBook $addressBook, Tag $tag): JsonResponse
    {
        abort_unless($tag->address_book_id === $addressBook->id, 404);

        $tag->update($this->tagColor($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ])));

        ConsoleAudit::record('address-book.tag-update', 'Updated tag '.$tag->name.' (API)', 'address_book', $addressBook->guid);

        return $this->ok($this->serializeTag($tag), 'Tag updated.');
    }

    /**
     * The API speaks "#RRGGBB"; the column holds the u32 ARGB the RustDesk
     * client uses. Passing the string straight through stored it as-is on
     * SQLite and failed with a 500 on MySQL and MariaDB. Null means no colour.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function tagColor(array $data): array
    {
        if (array_key_exists('color', $data)) {
            $data['color'] = $data['color'] === null ? 0 : AddressBookManager::hexToColor($data['color']);
        }

        return $data;
    }

    /** @return array{id: int, name: string, color: string|null} */
    private function serializeTag(Tag $tag): array
    {
        $color = (int) $tag->color;

        return ['id' => $tag->id, 'name' => $tag->name, 'color' => $color === 0 ? null : sprintf('#%06X', $color & 0xFFFFFF)];
    }

    public function destroyTag(AddressBook $addressBook, Tag $tag): JsonResponse
    {
        abort_unless($tag->address_book_id === $addressBook->id, 404);
        $name = $tag->name;
        $tag->delete();

        ConsoleAudit::record('address-book.tag-delete', 'Deleted tag '.$name.' (API)', 'address_book', $addressBook->guid);

        return $this->ok(null, 'Tag deleted.');
    }

    // ---- Rules -------------------------------------------------------------

    public function rules(AddressBook $addressBook): JsonResponse
    {
        return $this->ok($addressBook->rules()->get()
            ->map(fn (AddressBookRule $r) => $this->serializeRule($r))->all());
    }

    public function storeRule(Request $request, AddressBook $addressBook): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['everyone', 'user', 'group'])],
            'subject_id' => ['nullable', 'integer'],
            'permission' => ['required', 'integer', Rule::in([
                AddressBookRule::PERM_READ,
                AddressBookRule::PERM_READ_WRITE,
                AddressBookRule::PERM_FULL,
            ])],
        ]);
        $data['address_book_id'] = $addressBook->id;
        if ($data['subject_type'] === 'everyone') {
            $data['subject_id'] = null;
        }

        $rule = AddressBookRule::create($data);

        ConsoleAudit::record('address-book.rule-add', 'Added AB rule (API)', 'address_book', $addressBook->guid);

        return $this->created($this->serializeRule($rule), 'Rule added.');
    }

    public function updateRule(Request $request, AddressBook $addressBook, AddressBookRule $rule): JsonResponse
    {
        abort_unless($rule->address_book_id === $addressBook->id, 404);

        $rule->update($request->validate([
            'subject_type' => ['sometimes', 'required', Rule::in(['everyone', 'user', 'group'])],
            'subject_id' => ['nullable', 'integer'],
            'permission' => ['sometimes', 'required', 'integer', Rule::in([
                AddressBookRule::PERM_READ,
                AddressBookRule::PERM_READ_WRITE,
                AddressBookRule::PERM_FULL,
            ])],
        ]));

        ConsoleAudit::record('address-book.rule-update', 'Updated AB rule (API)', 'address_book', $addressBook->guid);

        return $this->ok($this->serializeRule($rule), 'Rule updated.');
    }

    public function destroyRule(AddressBook $addressBook, AddressBookRule $rule): JsonResponse
    {
        abort_unless($rule->address_book_id === $addressBook->id, 404);
        $rule->delete();

        ConsoleAudit::record('address-book.rule-delete', 'Deleted AB rule (API)', 'address_book', $addressBook->guid);

        return $this->ok(null, 'Rule deleted.');
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * The write-only `password` (#151), as the column update to apply: [] when
     * the field is absent (keep it), null password_enc for null or "" (clear
     * it). Shared books only, as in the client API: a personal book keeps a
     * client-side hash, which the console cannot make from a password.
     *
     * @param  array<string,mixed>  $data  validated input; `password` is taken out
     * @return array{password_enc?: ?string}
     */
    private function passwordUpdate(Request $request, AddressBook $book, array &$data): array
    {
        if (! array_key_exists('password', $data)) {
            return [];
        }
        $password = (string) $data['password'];
        unset($data['password']);
        if ($password !== '' && $book->is_personal) {
            throw ValidationException::withMessages(['password' => 'Passwords can be set only in shared address books.']);
        }

        return ['password_enc' => $password === '' ? null : Crypt::encryptString($password)];
    }

    private function validatePeer(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'rustdesk_id' => [$req, 'string', 'max:255'],
            'alias' => ['nullable', 'string', 'max:255'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:64'],
            'username' => ['nullable', 'string', 'max:255'],
            'login_name' => ['nullable', 'string', 'max:255'],
            'force_always_relay' => ['boolean'],
            'rdp_port' => ['nullable', 'integer'],
            'rdp_username' => ['nullable', 'string', 'max:255'],
            'tag_ids' => ['nullable', 'array'],
        ]);
    }

    private function serializeBook(AddressBook $book): array
    {
        return [
            'id' => $book->id,
            'guid' => $book->guid,
            'name' => $book->name,
            'is_personal' => (bool) $book->is_personal,
            'owner' => $book->owner ? ['id' => $book->owner->id, 'username' => $book->owner->username] : null,
            'note' => $book->note,
            'peers_count' => $book->entries_count ?? $book->entries()->count(),
            'tags_count' => $book->tags_count ?? $book->tags()->count(),
            'rules_count' => $book->rules_count ?? $book->rules()->count(),
        ];
    }

    private function serializePeer(AddressBookEntry $e): array
    {
        return [
            'id' => $e->id,
            'rustdesk_id' => $e->rustdesk_id,
            'alias' => $e->alias,
            'hostname' => $e->hostname,
            'platform' => $e->platform,
            'username' => $e->username,
            'login_name' => $e->login_name,
            'force_always_relay' => (bool) $e->force_always_relay,
            'rdp_port' => $e->rdp_port,
            'rdp_username' => $e->rdp_username,
            'tags' => $e->tag_ids ?? [],
            'has_password' => $e->password_enc !== null,
        ];
    }

    private function serializeRule(AddressBookRule $r): array
    {
        return [
            'id' => $r->id,
            'subject_type' => $r->subject_type,
            'subject_id' => $r->subject_id,
            'permission' => (int) $r->permission,
        ];
    }
}
