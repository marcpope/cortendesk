<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\ApiToken;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * RustDesk client `--assign` support (PLAN B2).
 *
 * The official client posts to `POST {api-server}/api/devices/cli` with a
 * Bearer API token (from B1's `api_tokens`, guard `auth:api-token`). The device
 * is matched/upserted by the posting client's own `id`+`uuid` in the body — NOT
 * a URL param — per docs/assign-protocol.md.
 *
 * Response convention (client `src/core_main.rs:631-641`): the client is
 * lenient — an EMPTY 200 body means success ("Done!"); any non-empty body is
 * printed verbatim, so errors return a short human-readable message.
 *
 * Permissions: the base assign needs a Device rw token (enforced by the
 * `api-token-can:device,rw` route middleware). Any `address_book_*` field
 * additionally needs address_book rw, and `strategy_name` additionally needs
 * strategy rw — both checked here, because the route middleware can only ask
 * one question and this endpoint reaches into three areas.
 */
class DeviceCliController extends Controller
{
    /** Assign flags that, if present, make the request actionable. */
    private const ASSIGN_FLAGS = [
        'user_name',
        'strategy_name',
        'address_book_name',
        'device_group_name',
        'note',
        'device_username',
        'device_name',
    ];

    /** Address-book sub-fields that require an address_book rw token. */
    private const AB_FIELDS = [
        'address_book_name',
        'address_book_tag',
        'address_book_alias',
        'address_book_password',
        'address_book_note',
    ];

    public function assign(Request $request): Response
    {
        $id = trim((string) $request->input('id'));
        $uuid = trim((string) $request->input('uuid'));

        if ($id === '' || $uuid === '') {
            return $this->message('id and uuid are required.', 400);
        }

        // At least one recognized assign flag must be present (the client
        // enforces this too — src/core_main.rs:581-599).
        $hasFlag = false;
        foreach (self::ASSIGN_FLAGS as $flag) {
            if ($request->filled($flag)) {
                $hasFlag = true;
                break;
            }
        }
        if (! $hasFlag) {
            return $this->message('No assignment parameters provided.', 400);
        }

        // Address-book fields require an address_book rw token on top of the
        // Device rw already enforced by the route middleware.
        $usesAb = false;
        foreach (self::AB_FIELDS as $field) {
            if ($request->filled($field)) {
                $usesAb = true;
                break;
            }
        }
        if ($usesAb && ! $this->tokenAllows($request, 'address_book')) {
            return $this->message("Token lacks 'rw' permission on 'address_book'.", 403);
        }

        // Same reasoning for `strategy_name`: pushing a policy is a strategy
        // write, and it is the highest-precedence one there is (see below). A
        // token scoped `strategy: none` must not be able to do it just because
        // it may also write devices — the automation API has no other strategy
        // route, so this check is the only place that permission is read.
        if ($request->filled('strategy_name') && ! $this->tokenAllows($request, 'strategy')) {
            return $this->message("Token lacks 'rw' permission on 'strategy'.", 403);
        }
        if ($request->filled('strategy_name')) {
            return $this->message(
                'Direct strategy assignment requires a reviewed impact confirmation in Strategies → Assign.',
                409,
            );
        }

        // Resolve the named user / device group up front so a bad name fails the
        // whole request before we mutate anything.
        $userChange = null; // [bool $set, ?int $id]
        if ($request->filled('user_name')) {
            $user = User::where('username', $request->input('user_name'))->first();
            if (! $user) {
                return $this->message("User '".$request->input('user_name')."' not found.", 404);
            }
            $userChange = $user->id;
        }

        $groupChange = null;
        if ($request->filled('device_group_name')) {
            $group = DeviceGroup::where('name', $request->input('device_group_name'))->first();
            if (! $group) {
                return $this->message("Device group '".$request->input('device_group_name')."' not found.", 404);
            }
            $groupChange = $group->id;
        }

        // Resolve the address book (if any) before mutating.
        $book = null;
        if ($request->filled('address_book_name')) {
            $book = AddressBook::where('name', $request->input('address_book_name'))->first();
            if (! $book) {
                return $this->message("Address book '".$request->input('address_book_name')."' not found.", 404);
            }
        }

        // Match/upsert the device on the posting client's identity. `rustdesk_id`
        // is the unique registered identifier in our schema, so it is the match
        // key; `uuid` is stored/refreshed alongside it (a reinstall can rotate
        // the machine uuid while keeping the same id).
        $device = Device::where('rustdesk_id', $id)->first();
        $isNew = $device === null;
        if ($isNew) {
            $device = new Device(['rustdesk_id' => $id]);
        }

        $attributes = [
            'uuid' => $uuid,
            // A deploy/assign carrying a Device rw token is an authenticated,
            // pre-approved registration (PLAN B3).
            'status' => Device::STATUS_ACTIVE,
        ];
        if ($userChange !== null) {
            $attributes['user_id'] = $userChange;
        }
        if ($groupChange !== null) {
            $attributes['device_group_id'] = $groupChange;
        }
        if ($request->filled('note')) {
            $attributes['note'] = (string) $request->input('note');
        }
        // Display overrides (NOT identifiers).
        if ($request->filled('device_name')) {
            $attributes['hostname'] = (string) $request->input('device_name');
        }
        if ($request->filled('device_username')) {
            $attributes['username'] = (string) $request->input('device_username');
        }

        $mayChangeResolvedStrategy = $this->tokenAllows($request, 'strategy');
        try {
            $device = DB::transaction(
                fn (): Device => Device::updateWithStrategyContext($device, $attributes, $mayChangeResolvedStrategy),
            );
        } catch (AuthorizationException $exception) {
            return $this->message($exception->getMessage(), 403);
        }

        // Add / update the address-book entry for this device.
        if ($book !== null) {
            $this->applyAddressBook($request, $book, $device);
        }

        ConsoleAudit::record(
            'device.assign',
            ($isNew ? 'Registered and assigned' : 'Assigned').' device '.$device->rustdesk_id.' via --assign (API)',
            'device',
            $device->rustdesk_id,
        );

        // Empty 200 body → client prints "Done!".
        return response('', 200);
    }

    /**
     * Upsert the device as a peer in the given address book, applying the
     * address_book_* display fields. address_book_note has no column in our
     * schema and is accepted-and-ignored.
     */
    private function applyAddressBook(Request $request, AddressBook $book, Device $device): void
    {
        $entry = AddressBookEntry::firstOrNew([
            'address_book_id' => $book->id,
            'rustdesk_id' => $device->rustdesk_id,
        ]);

        $entry->hostname = $device->hostname;
        $entry->platform = $device->platform();
        $entry->username = $device->username;

        if ($request->filled('address_book_alias')) {
            $entry->alias = (string) $request->input('address_book_alias');
        }
        if ($request->filled('address_book_password')) {
            $entry->password_enc = Crypt::encryptString((string) $request->input('address_book_password'));
        }
        if ($request->filled('address_book_tag')) {
            $tag = Tag::firstOrCreate([
                'address_book_id' => $book->id,
                'name' => (string) $request->input('address_book_tag'),
            ]);
            $tagIds = $entry->tag_ids ?? [];
            if (! in_array($tag->id, $tagIds, true)) {
                $tagIds[] = $tag->id;
            }
            $entry->tag_ids = $tagIds;
        }

        $entry->save();
    }

    /** Does the bearer token behind this request hold rw on a resource? */
    private function tokenAllows(Request $request, string $resource): bool
    {
        $token = $request->attributes->get('api_token');

        return $token instanceof ApiToken && $token->allows($resource, 'rw');
    }

    /** Non-empty body the client prints verbatim; status for API-aware callers. */
    private function message(string $text, int $status): Response
    {
        return response($text, $status)->header('Content-Type', 'text/plain');
    }
}
