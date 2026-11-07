<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\AddressBookRule;
use App\Models\Device;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

class AddressBookController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | New multi-address-book API (client ≥ 1.2.6) — spec §11–§17
    |--------------------------------------------------------------------------
    */

    /** POST /api/ab/personal — the legacy/new negotiation probe (§12). */
    public function personal(Request $request): JsonResponse
    {
        $book = AddressBook::personalFor($request->user());

        return response()->json(['guid' => $book->guid]);
    }

    /** POST /api/ab/settings (§11). */
    public function settings(): JsonResponse
    {
        return response()->json(['max_peer_one_ab' => 0]);
    }

    /** POST /api/ab/shared/profiles?current&pageSize (§13). */
    public function sharedProfiles(Request $request): JsonResponse
    {
        $user = $request->user();
        $books = $this->accessibleSharedBooks($user);

        [$page, $size] = $this->pagination($request);
        $slice = $books->slice(($page - 1) * $size, $size)->values();

        return response()->json([
            'total' => $books->count(),
            'data' => $slice->map(fn (array $row) => [
                'guid' => $row['book']->guid,
                'name' => $row['book']->name,
                'owner' => $row['book']->owner?->username ?? '',
                'note' => (string) $row['book']->note,
                'rule' => $row['rule'],
            ])->all(),
        ]);
    }

    /** POST /api/ab/peers?current&pageSize&ab=<guid> (§14). */
    public function peers(Request $request): JsonResponse
    {
        $book = $this->bookOrFail($request, (string) $request->query('ab', ''), AddressBookRule::PERM_READ);
        if ($book instanceof JsonResponse) {
            return $book;
        }

        [$page, $size] = $this->pagination($request);
        $query = $book->entries()->orderBy('id');
        $total = (clone $query)->count();

        $data = $query->forPage($page, $size)->get()
            ->map(fn (AddressBookEntry $e) => $this->peerJson($e, $book))
            ->all();

        return response()->json(['total' => $total, 'data' => $data]);
    }

    /** POST /api/ab/tags/{guid} — bare array response, color REQUIRED int (§15). */
    public function tags(Request $request, string $guid): JsonResponse
    {
        $book = $this->bookOrFail($request, $guid, AddressBookRule::PERM_READ);
        if ($book instanceof JsonResponse) {
            return $book;
        }

        return response()->json(
            $book->tags()->orderBy('name')->get()
                ->map(fn (Tag $t) => ['name' => $t->name, 'color' => (int) $t->color])
                ->all()
        );
    }

    /** POST /api/ab/peer/add/{guid} — success = HTTP 200 EMPTY body (§16). */
    public function peerAdd(Request $request, string $guid): Response|JsonResponse
    {
        $book = $this->bookOrFail($request, $guid, AddressBookRule::PERM_READ_WRITE);
        if ($book instanceof JsonResponse) {
            return $book;
        }

        $id = (string) $request->input('id', '');
        if ($id === '') {
            return response()->json(['error' => 'id is required']);
        }

        if ($book->entries()->where('rustdesk_id', $id)->exists()) {
            return response()->json(['error' => 'Peer already exists']);
        }

        // The client's group tab has no alias to send (its PeerPayload never
        // carries one), so an add from the client always arrives blank even
        // when the console has a name for the device. Fill it from the fleet —
        // but only when blank, so a name typed in the client wins, and only
        // from devices this user may see (issue #28).
        $alias = (string) $request->input('alias', '');
        if ($alias === '') {
            $alias = (string) (Device::visibleTo($request->user())
                ->where('rustdesk_id', $id)->value('alias') ?? '');
        }

        $book->entries()->create([
            'rustdesk_id' => $id,
            'alias' => $alias,
            'username' => (string) $request->input('username', ''),
            'hostname' => (string) $request->input('hostname', ''),
            'platform' => (string) $request->input('platform', ''),
            'hash' => $book->is_personal ? (string) $request->input('hash', '') : null,
            'password_enc' => ! $book->is_personal && $request->filled('password')
                ? Crypt::encryptString((string) $request->input('password'))
                : null,
            'tag_ids' => $this->tagNamesToIds($book, (array) $request->input('tags', [])),
        ]);

        return response('', 200);
    }

    /** PUT /api/ab/peer/update/{guid} — partial update; absent = unchanged (§16). */
    public function peerUpdate(Request $request, string $guid): Response|JsonResponse
    {
        $book = $this->bookOrFail($request, $guid, AddressBookRule::PERM_READ_WRITE);
        if ($book instanceof JsonResponse) {
            return $book;
        }

        $entry = $book->entries()->where('rustdesk_id', (string) $request->input('id', ''))->first();
        if ($entry === null) {
            return response()->json(['error' => 'Peer not found']);
        }

        $updates = [];
        foreach (['alias', 'username', 'hostname', 'platform'] as $field) {
            if ($request->has($field)) {
                $updates[$field] = (string) $request->input($field);
            }
        }
        if ($request->has('tags')) {
            $updates['tag_ids'] = $this->tagNamesToIds($book, (array) $request->input('tags'));
        }
        if ($request->has('hash') && $book->is_personal) {
            $updates['hash'] = (string) $request->input('hash');
        }
        if ($request->has('password') && ! $book->is_personal) {
            $updates['password_enc'] = Crypt::encryptString((string) $request->input('password'));
        }

        $entry->update($updates);

        return response('', 200);
    }

    /** DELETE /api/ab/peer/{guid} — body = JSON array of ids (§16). */
    public function peerDelete(Request $request, string $guid): Response|JsonResponse
    {
        $book = $this->bookOrFail($request, $guid, AddressBookRule::PERM_READ_WRITE);
        if ($book instanceof JsonResponse) {
            return $book;
        }

        $ids = array_filter(array_map('strval', (array) $request->json()->all()));
        if ($ids !== []) {
            $book->entries()->whereIn('rustdesk_id', $ids)->delete();
        }

        return response('', 200);
    }

    /** POST /api/ab/tag/add/{guid} (§17). Managing tags requires FULL (B4). */
    public function tagAdd(Request $request, string $guid): Response|JsonResponse
    {
        $book = $this->bookOrFail($request, $guid, AddressBookRule::PERM_FULL);
        if ($book instanceof JsonResponse) {
            return $book;
        }

        $name = (string) $request->input('name', '');
        if ($name === '') {
            return response()->json(['error' => 'name is required']);
        }

        $book->tags()->firstOrCreate(['name' => $name], ['color' => (int) $request->input('color', 0)]);

        return response('', 200);
    }

    /** PUT /api/ab/tag/rename/{guid} — {"old","new"} (§17). Requires FULL (B4). */
    public function tagRename(Request $request, string $guid): Response|JsonResponse
    {
        $book = $this->bookOrFail($request, $guid, AddressBookRule::PERM_FULL);
        if ($book instanceof JsonResponse) {
            return $book;
        }

        $tag = $book->tags()->where('name', (string) $request->input('old', ''))->first();
        if ($tag === null) {
            return response()->json(['error' => 'Tag not found']);
        }

        $tag->update(['name' => (string) $request->input('new', '')]);

        // Entries reference tags by id, so renames propagate automatically.
        return response('', 200);
    }

    /** PUT /api/ab/tag/update/{guid} — color change (§17). Requires FULL (B4). */
    public function tagUpdate(Request $request, string $guid): Response|JsonResponse
    {
        $book = $this->bookOrFail($request, $guid, AddressBookRule::PERM_FULL);
        if ($book instanceof JsonResponse) {
            return $book;
        }

        $book->tags()
            ->where('name', (string) $request->input('name', ''))
            ->update(['color' => (int) $request->input('color', 0)]);

        return response('', 200);
    }

    /** DELETE /api/ab/tag/{guid} — body = JSON array of names (§17). Requires FULL (B4). */
    public function tagDelete(Request $request, string $guid): Response|JsonResponse
    {
        $book = $this->bookOrFail($request, $guid, AddressBookRule::PERM_FULL);
        if ($book instanceof JsonResponse) {
            return $book;
        }

        $names = array_filter(array_map('strval', (array) $request->json()->all()));
        $tagIds = $book->tags()->whereIn('name', $names)->pluck('id');

        if ($tagIds->isNotEmpty()) {
            // Detach from entries, then delete.
            $book->entries()->get()->each(function (AddressBookEntry $e) use ($tagIds) {
                $kept = array_values(array_diff($e->tag_ids ?? [], $tagIds->all()));
                if ($kept !== ($e->tag_ids ?? [])) {
                    $e->update(['tag_ids' => $kept]);
                }
            });
            Tag::whereIn('id', $tagIds)->delete();
        }

        return response('', 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Legacy address book (client < 1.2.6 and Sciter) — spec §10
    |--------------------------------------------------------------------------
    */

    /** GET /api/ab (+ POST /api/ab/get alias) — triple-encoded pull. */
    public function legacyGet(Request $request): JsonResponse
    {
        $book = AddressBook::personalFor($request->user());
        $tags = $book->tags()->get();

        $peers = $book->entries()->get()->map(function (AddressBookEntry $e) use ($tags) {
            return [
                'id' => $e->rustdesk_id,
                'username' => (string) $e->username,
                'hostname' => (string) $e->hostname,
                'platform' => (string) $e->platform,
                'alias' => (string) $e->alias,
                'tags' => $tags->whereIn('id', $e->tag_ids ?? [])->pluck('name')->values()->all(),
                'hash' => (string) $e->hash,
            ];
        })->all();

        $payload = [
            'tags' => $tags->pluck('name')->all(),
            'peers' => $peers,
            // tag_colors is a JSON string INSIDE the JSON-string data field.
            'tag_colors' => json_encode($tags->pluck('color', 'name')->map(fn ($c) => (int) $c)->all()),
        ];

        return response()->json([
            'data' => json_encode($payload),
            'licensed_devices' => 0,
        ]);
    }

    /** POST /api/ab — full replace of the personal book (§10). */
    public function legacyPush(Request $request): JsonResponse
    {
        $book = AddressBook::personalFor($request->user());

        $decoded = json_decode((string) $request->input('data', ''), true);
        if (! is_array($decoded)) {
            return response()->json(['error' => 'Malformed address book payload']);
        }

        $tagColors = json_decode((string) ($decoded['tag_colors'] ?? ''), true) ?: [];

        // Rebuild tags.
        $book->tags()->delete();
        $tagIdByName = [];
        foreach ((array) ($decoded['tags'] ?? []) as $name) {
            $tag = $book->tags()->create([
                'name' => (string) $name,
                'color' => (int) ($tagColors[$name] ?? 0),
            ]);
            $tagIdByName[$name] = $tag->id;
        }

        // Rebuild entries.
        $book->entries()->delete();
        foreach ((array) ($decoded['peers'] ?? []) as $peer) {
            if (! is_array($peer) || ($peer['id'] ?? '') === '') {
                continue;
            }
            $book->entries()->create([
                'rustdesk_id' => (string) $peer['id'],
                'username' => (string) ($peer['username'] ?? ''),
                'hostname' => (string) ($peer['hostname'] ?? ''),
                'platform' => (string) ($peer['platform'] ?? ''),
                'alias' => (string) ($peer['alias'] ?? ''),
                'hash' => (string) ($peer['hash'] ?? ''),
                'tag_ids' => array_values(array_filter(array_map(
                    fn ($n) => $tagIdByName[$n] ?? null,
                    (array) ($peer['tags'] ?? [])
                ))),
            ]);
        }

        return response()->json((object) []);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** @return array{0:int,1:int} [current, pageSize] */
    private function pagination(Request $request): array
    {
        return [
            max(1, (int) $request->query('current', 1)),
            min(500, max(1, (int) $request->query('pageSize', 100))),
        ];
    }

    /**
     * Shared books this user can access, with the effective permission rule.
     *
     * @return Collection<int, array{book: AddressBook, rule: int}>
     */
    private function accessibleSharedBooks(User $user)
    {
        $groupIds = $user->is_admin
            ? []
            : $user->groups()->pluck('user_groups.id')->all();

        return AddressBook::query()
            ->where('is_personal', false)
            ->with(['owner', 'rules'])
            ->get()
            ->map(function (AddressBook $book) use ($user, $groupIds) {
                return ['book' => $book, 'rule' => $this->ruleFor($book, $user, $groupIds)];
            })
            ->filter(fn (array $row) => $row['rule'] > 0)
            ->sortBy(fn (array $row) => $row['book']->name)
            ->values();
    }

    /**
     * Effective permission of $user on $book: 0 = none, 1..3 per spec §13.
     * Delegates to AddressBook::permissionFor so the console and the client API
     * share one ro / rw / full authority (PLAN B4).
     */
    private function ruleFor(AddressBook $book, User $user, ?array $groupIds = null): int
    {
        return $book->permissionFor($user, $groupIds);
    }

    /** Resolve a book by guid and enforce the required permission level. */
    private function bookOrFail(Request $request, string $guid, int $required): AddressBook|JsonResponse
    {
        $book = AddressBook::where('guid', $guid)->first();

        if ($book === null) {
            return response()->json(['error' => 'Address book not found']);
        }

        if ($this->ruleFor($book, $request->user()) < $required) {
            return response()->json(['error' => 'Permission denied']);
        }

        return $book;
    }

    /** Map client tag names to our tag ids within a book. */
    private function tagNamesToIds(AddressBook $book, array $names): array
    {
        return $book->tags()
            ->whereIn('name', array_map('strval', $names))
            ->pluck('id')
            ->all();
    }

    /** Peer JSON per spec §10/§14 — note forceAlwaysRelay is a STRING. */
    private function peerJson(AddressBookEntry $e, AddressBook $book): array
    {
        $peer = [
            'id' => $e->rustdesk_id,
            'username' => (string) $e->username,
            'hostname' => (string) $e->hostname,
            'platform' => (string) $e->platform,
            'alias' => (string) $e->alias,
            'tags' => $book->tags->whereIn('id', $e->tag_ids ?? [])->pluck('name')->values()->all(),
            'forceAlwaysRelay' => $e->force_always_relay ? 'true' : 'false',
            'rdpPort' => (string) $e->rdp_port,
            'rdpUsername' => (string) $e->rdp_username,
            'loginName' => (string) $e->login_name,
        ];

        if ($book->is_personal) {
            $peer['hash'] = (string) $e->hash;
        } elseif ($e->password_enc !== null) {
            $peer['password'] = Crypt::decryptString($e->password_enc);
        } elseif ($book->default_password_enc !== null) {
            // The book's default covers every entry without its own (#162).
            $peer['password'] = Crypt::decryptString($book->default_password_enc);
        }

        return $peer;
    }
}
