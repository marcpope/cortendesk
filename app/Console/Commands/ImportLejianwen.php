<?php

namespace App\Console\Commands;

use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\AddressBookRule;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\ClientToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\LoginLog;
use App\Models\Strategy;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserGroup;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImportLejianwen extends Command
{
    protected $signature = 'cortendesk:import-lejianwen
        {path : path to rustdeskapi.db}
        {--dry-run : Run the whole import inside a transaction, then roll back}
        {--wipe : Truncate target tables first}';

    protected $description = 'Import users, devices, address books and audit logs from a lejianwen/rustdesk-api SQLite database';

    /** @var array<string, array{imported: int, updated: int, skipped: int}> */
    private array $stats = [];

    /** @var list<string> usernames imported with a random password (non-bcrypt source hash) */
    private array $needsPasswordReset = [];

    private int $skippedEntryPasswords = 0;

    private int $skippedEmptyPeerIds = 0;

    /** @var array<int, int> source user id => target user id */
    private array $userMap = [];

    /** @var array<int, string> source user id => username */
    private array $usernameMap = [];

    /** @var array<int, int> source `groups`.id (type==1) => user_groups.id */
    private array $userGroupMap = [];

    /** @var array<int, int> source `device_groups`.id => device_groups.id */
    private array $deviceGroupMap = [];

    /** @var array<int, int> source collection id => address_books.id */
    private array $collectionMap = [];

    /** @var array<int, int> target user id => personal address_books.id */
    private array $personalBookMap = [];

    /** @var array<int, array<string, int>> address_book_id => [tag name => tag id] */
    private array $tagNameMap = [];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("Source database not found: {$path}");

            return self::FAILURE;
        }

        config(['database.connections.lejianwen' => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('lejianwen');
        $source = DB::connection('lejianwen');

        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();

        try {
            if ($this->option('wipe')) {
                $this->wipeTargets();
            }

            $this->importGroups($source);
            $this->importUsers($source);
            $this->createPersonalAddressBooks();
            $this->importCollections($source);
            $this->importTags($source);
            $this->importAddressBookEntries($source);
            $this->importPeers($source);
            $this->importAuditConnections($source);
            $this->importAuditFiles($source);
            $this->importLoginLogs($source);
            $this->importActiveTokens($source);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $this->printSummary($dryRun);

        return self::SUCCESS;
    }

    private function wipeTargets(): void
    {
        $this->warn('Wiping target tables...');

        // delete() instead of truncate() so --wipe stays inside the
        // transaction (and rolls back under --dry-run).
        Strategy::query()->orderBy('id')->lockForUpdate()->get(['id']);
        Device::withTrashed()->forceDelete();

        foreach ([
            AddressBookEntry::class, Tag::class, AddressBookRule::class,
            AddressBook::class, AuditConnection::class, AuditFileTransfer::class,
            LoginLog::class, User::class, UserGroup::class, DeviceGroup::class,
        ] as $model) {
            $model::query()->delete();
        }
    }

    // ------------------------------------------------------------------ groups

    private function importGroups(Connection $source): void
    {
        // The `groups` table is lejianwen's USER groups — `users.group_id`
        // references it. Its `type` column only distinguishes shared (1) vs
        // personal/single (2) user groups; BOTH are user groups. Device
        // grouping lives entirely in the separate `device_groups` table.
        if (! $this->tableMissing($source, 'groups')) {
            foreach ($source->table('groups')->orderBy('id')->get() as $row) {
                $group = UserGroup::updateOrCreate(['name' => $row->name]);
                $this->track('user_groups', $group);
                $this->userGroupMap[(int) $row->id] = $group->id;
            }
        }

        if (! $this->tableMissing($source, 'device_groups')) {
            foreach ($source->table('device_groups')->orderBy('id')->get() as $row) {
                $group = DeviceGroup::updateOrCreate(['name' => $row->name]);
                $this->track('device_groups', $group);
                $this->deviceGroupMap[(int) $row->id] = $group->id;
            }
        }
    }

    // ------------------------------------------------------------------- users

    private function importUsers(Connection $source): void
    {
        if ($this->tableMissing($source, 'users')) {
            return;
        }

        $usedEmails = User::query()->whereNotNull('email')->pluck('email', 'username')->all();

        foreach ($source->table('users')->orderBy('id')->get() as $row) {
            $username = trim((string) $row->username);

            if ($username === '') {
                $this->bump('users', 'skipped');

                continue;
            }

            $email = trim((string) $row->email) ?: null;

            // users.email is unique+nullable in our schema; the source only
            // indexes it non-uniquely, so drop duplicate emails.
            if ($email !== null) {
                $holder = array_search($email, $usedEmails, true);

                if ($holder !== false && $holder !== $username) {
                    $this->warn("Duplicate email `{$email}` on user `{$username}` — imported without email.");
                    $email = null;
                } else {
                    $usedEmails[$username] = $email;
                }
            }

            $user = User::query()->firstOrNew(['username' => $username]);
            $isNew = ! $user->exists;

            $user->fill([
                'email' => $email,
                'name' => trim((string) $row->nickname) ?: null,
                'avatar' => trim((string) $row->avatar) ?: null,
                'is_admin' => (bool) $row->is_admin,
                'is_active' => (int) $row->status === 1,
                'note' => trim((string) $row->remark) ?: null,
            ]);

            if ($isNew) {
                // Placeholder; the real hash is written verbatim below.
                $user->password = Str::random(40);
            }

            $user->save();
            $this->track('users', $user, $isNew);

            // lejianwen users carry a single group_id — mirror it via the pivot.
            $groupId = $this->userGroupMap[(int) $row->group_id] ?? null;
            $user->groups()->sync($groupId !== null ? [$groupId] : []);

            // lejianwen stores Go bcrypt hashes ($2a$.../$2b$...). PHP's crypt
            // engine verifies these, but Laravel's BcryptHasher::check() throws
            // "does not use the Bcrypt algorithm" for any prefix other than
            // $2y$ — so normalize the marker ($2a$/$2b$/$2x$ → $2y$; identical
            // algorithm, salt and digest) and write it with a raw update (the
            // `hashed` cast would re-hash the string).
            if (preg_match('/^\$2[abxy]\$/', (string) $row->password)) {
                $hash = '$2y$'.substr((string) $row->password, 4);
                DB::table('users')->where('id', $user->id)->update(['password' => $hash]);
            } else {
                DB::table('users')->where('id', $user->id)
                    ->update(['password' => password_hash(Str::random(40), PASSWORD_BCRYPT, ['cost' => 10])]);
                $this->needsPasswordReset[] = $username;
            }

            $this->userMap[(int) $row->id] = $user->id;
            $this->usernameMap[(int) $row->id] = $username;
        }
    }

    // ---------------------------------------------------------- address books

    private function createPersonalAddressBooks(): void
    {
        foreach ($this->userMap as $targetUserId) {
            $book = AddressBook::query()->firstOrNew([
                'owner_user_id' => $targetUserId,
                'is_personal' => true,
            ]);
            $isNew = ! $book->exists;
            $book->name = $book->name ?: 'My address book';
            $book->save();

            $this->track('address_books', $book, $isNew);
            $this->personalBookMap[$targetUserId] = $book->id;
        }
    }

    private function importCollections(Connection $source): void
    {
        if ($this->tableMissing($source, 'address_book_collections')) {
            return;
        }

        foreach ($source->table('address_book_collections')->orderBy('id')->get() as $row) {
            $ownerId = $this->userMap[(int) $row->user_id] ?? null;

            if ($ownerId === null) {
                $this->warn("Collection `{$row->name}` (#{$row->id}) has unknown owner user #{$row->user_id} — skipped.");
                $this->bump('address_books', 'skipped');

                continue;
            }

            $book = AddressBook::updateOrCreate(
                ['owner_user_id' => $ownerId, 'name' => (string) $row->name, 'is_personal' => false],
                [],
            );
            $this->track('address_books', $book);
            $this->collectionMap[(int) $row->id] = $book->id;
        }
    }

    /**
     * Resolve a source (user_id, collection_id) pair to a target address book:
     * collection 0 means the user's personal book, anything else a shared one.
     */
    private function resolveBook(int $sourceUserId, int $collectionId): ?int
    {
        if ($collectionId === 0) {
            $targetUserId = $this->userMap[$sourceUserId] ?? null;

            return $targetUserId !== null ? ($this->personalBookMap[$targetUserId] ?? null) : null;
        }

        return $this->collectionMap[$collectionId] ?? null;
    }

    private function importTags(Connection $source): void
    {
        if ($this->tableMissing($source, 'tags')) {
            return;
        }

        foreach ($source->table('tags')->orderBy('id')->get() as $row) {
            $bookId = $this->resolveBook((int) $row->user_id, (int) $row->collection_id);

            if ($bookId === null) {
                $this->warn("Tag `{$row->name}` (#{$row->id}) belongs to an unresolvable book — skipped.");
                $this->bump('tags', 'skipped');

                continue;
            }

            $tag = Tag::updateOrCreate(
                ['address_book_id' => $bookId, 'name' => (string) $row->name],
                ['color' => (int) $row->color],
            );
            $this->track('tags', $tag);
            $this->tagNameMap[$bookId][$tag->name] = $tag->id;
        }
    }

    private function importAddressBookEntries(Connection $source): void
    {
        // Source table `address_books` holds the ENTRIES (peers).
        if ($this->tableMissing($source, 'address_books')) {
            return;
        }

        // The client-side password *hash* (distinct from the undecryptable
        // encrypted password) is portable; import it when our schema has the
        // column (added by a later migration).
        $hasHashColumn = DB::getSchemaBuilder()->hasColumn('address_book_entries', 'hash');

        foreach ($source->table('address_books')->orderBy('row_id')->get() as $row) {
            $rustdeskId = trim((string) $row->id);

            if ($rustdeskId === '' || $rustdeskId === '0') {
                $this->bump('address_book_entries', 'skipped');

                continue;
            }

            $bookId = $this->resolveBook((int) $row->user_id, (int) $row->collection_id);

            if ($bookId === null) {
                $this->warn("Address book entry `{$rustdeskId}` belongs to an unresolvable book — skipped.");
                $this->bump('address_book_entries', 'skipped');

                continue;
            }

            // Stored peer passwords are encrypted with the Go app's key and
            // cannot be decrypted here.
            if (trim((string) $row->password) !== '') {
                $this->skippedEntryPasswords++;
            }

            $entry = AddressBookEntry::query()->firstOrNew(
                ['address_book_id' => $bookId, 'rustdesk_id' => $rustdeskId],
            );
            $isNew = ! $entry->exists;
            $entry->fill([
                'alias' => trim((string) $row->alias) ?: null,
                'hostname' => trim((string) $row->hostname) ?: null,
                'platform' => trim((string) $row->platform) ?: null,
                'username' => trim((string) $row->username) ?: null,
                'login_name' => trim((string) $row->login_name) ?: null,
                'force_always_relay' => (bool) $row->force_always_relay,
                'rdp_port' => trim((string) $row->rdp_port) ?: null,
                'rdp_username' => trim((string) $row->rdp_username) ?: null,
                'tag_ids' => $this->resolveTagIds($bookId, (string) $row->tags),
            ]);

            if ($hasHashColumn) {
                $entry->forceFill(['hash' => trim((string) ($row->hash ?? '')) ?: null]);
            }

            $entry->save();
            $this->track('address_book_entries', $entry, $isNew);
        }
    }

    /**
     * The source stores a JSON array of tag NAMES; resolve to tag ids within
     * the same book, creating any tag the source forgot to persist.
     *
     * @return list<int>
     */
    private function resolveTagIds(int $bookId, string $json): array
    {
        $names = json_decode($json, true);

        if (! is_array($names)) {
            return [];
        }

        $ids = [];

        foreach ($names as $name) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            if (! isset($this->tagNameMap[$bookId][$name])) {
                $tag = Tag::updateOrCreate(['address_book_id' => $bookId, 'name' => $name]);
                $this->track('tags', $tag);
                $this->tagNameMap[$bookId][$name] = $tag->id;
            }

            $ids[] = $this->tagNameMap[$bookId][$name];
        }

        return array_values(array_unique($ids));
    }

    // ------------------------------------------------------------------- peers

    private function importPeers(Connection $source): void
    {
        if ($this->tableMissing($source, 'peers')) {
            return;
        }

        foreach ($source->table('peers')->orderBy('row_id')->get() as $row) {
            $rustdeskId = trim((string) $row->id);

            if ($rustdeskId === '') {
                $this->skippedEmptyPeerIds++;
                $this->bump('devices', 'skipped');

                continue;
            }

            $sourceGroupId = (int) $row->group_id;

            $device = Device::withTrashed()->firstOrNew(['rustdesk_id' => $rustdeskId]);
            $isNew = ! $device->exists;
            $attributes = [
                'uuid' => (string) $row->uuid,
                'hostname' => trim((string) $row->hostname) ?: null,
                'os' => trim((string) $row->os) ?: null,
                'cpu' => trim((string) $row->cpu) ?: null,
                'memory' => trim((string) $row->memory) ?: null,
                'username' => trim((string) $row->username) ?: null,
                'version' => trim((string) $row->version) ?: null,
                'alias' => trim((string) $row->alias) ?: null,
                'user_id' => $this->userMap[(int) $row->user_id] ?? null,
                'device_group_id' => $this->deviceGroupMap[$sourceGroupId] ?? null,
                'last_online_at' => (int) $row->last_online_time > 0
                    ? Carbon::createFromTimestamp((int) $row->last_online_time)
                    : null,
                'last_online_ip' => trim((string) $row->last_online_ip) ?: null,
            ];
            $device = Device::updateWithStrategyContext($device, $attributes);
            $this->track('devices', $device, $isNew);
        }
    }

    // ------------------------------------------------------------------- audit

    private function importAuditConnections(Connection $source): void
    {
        if ($this->tableMissing($source, 'audit_conns')) {
            return;
        }

        foreach ($source->table('audit_conns')->orderBy('id')->get() as $row) {
            $this->importPreservingTimestamps(
                new AuditConnection,
                'audit_connections',
                [
                    'conn_id' => (int) $row->conn_id,
                    'rustdesk_id' => (string) $row->peer_id,
                    'uuid' => trim((string) $row->uuid) ?: null,
                    'created_at' => $this->parseTime($row->created_at),
                ],
                [
                    'action' => trim((string) $row->action) ?: null,
                    'from_peer' => trim((string) $row->from_peer) ?: null,
                    'from_name' => trim((string) $row->from_name) ?: null,
                    'ip' => trim((string) $row->ip) ?: null,
                    'session_id' => trim((string) $row->session_id) ?: null,
                    'conn_type' => (int) $row->type,
                    'closed_at' => (int) $row->close_time > 0
                        ? Carbon::createFromTimestamp((int) $row->close_time)
                        : null,
                    'updated_at' => $this->parseTime($row->updated_at),
                ],
            );
        }
    }

    private function importAuditFiles(Connection $source): void
    {
        if ($this->tableMissing($source, 'audit_files')) {
            return;
        }

        foreach ($source->table('audit_files')->orderBy('id')->get() as $row) {
            $this->importPreservingTimestamps(
                new AuditFileTransfer,
                'audit_file_transfers',
                [
                    'rustdesk_id' => (string) $row->peer_id,
                    'uuid' => trim((string) $row->uuid) ?: null,
                    'path' => (string) $row->path,
                    'created_at' => $this->parseTime($row->created_at),
                ],
                [
                    'from_peer' => trim((string) $row->from_peer) ?: null,
                    'from_name' => trim((string) $row->from_name) ?: null,
                    'info' => trim((string) $row->info) ?: null,
                    'is_file' => (bool) $row->is_file,
                    'direction' => (int) $row->type,
                    'file_count' => (int) $row->num,
                    'ip' => trim((string) $row->ip) ?: null,
                    'updated_at' => $this->parseTime($row->updated_at),
                ],
            );
        }
    }

    private function importLoginLogs(Connection $source): void
    {
        if ($this->tableMissing($source, 'login_logs')) {
            return;
        }

        foreach ($source->table('login_logs')->orderBy('id')->get() as $row) {
            if ((int) ($row->is_deleted ?? 0) === 1) {
                $this->bump('login_logs', 'skipped');

                continue;
            }

            // The source table has no username column; resolve it from the
            // imported users map.
            $username = $this->usernameMap[(int) $row->user_id] ?? 'unknown';

            $this->importPreservingTimestamps(
                new LoginLog,
                'login_logs',
                [
                    'username' => $username,
                    'client' => trim((string) $row->client) ?: 'unknown',
                    'device_id' => trim((string) $row->device_id) ?: null,
                    'ip' => trim((string) $row->ip) ?: null,
                    'created_at' => $this->parseTime($row->created_at),
                ],
                [
                    'user_id' => $this->userMap[(int) $row->user_id] ?? null,
                    'device_os' => trim((string) ($row->platform ?? '')) ?: null,
                    'successful' => true,
                    'updated_at' => $this->parseTime($row->updated_at),
                ],
            );
        }
    }

    /**
     * Carry over UNEXPIRED client session tokens so already-logged-in
     * RustDesk clients keep working across an in-place server swap
     * (no re-login, address book sync uninterrupted).
     */
    private function importActiveTokens(Connection $source): void
    {
        if ($this->tableMissing($source, 'user_tokens')) {
            return;
        }

        foreach ($source->table('user_tokens')->orderBy('id')->get() as $row) {
            $expiresAt = (int) $row->expired_at;
            $userId = $this->userMap[(int) $row->user_id] ?? null;
            $token = trim((string) $row->token);

            if ($userId === null || $token === '' || $expiresAt <= time()) {
                $this->bump('client_tokens', 'skipped');

                continue;
            }

            $this->track('client_tokens', ClientToken::updateOrCreate(
                ['token' => $token],
                [
                    'user_id' => $userId,
                    'device_id' => trim((string) $row->device_id) ?: null,
                    'device_uuid' => trim((string) $row->device_uuid) ?: null,
                    'expires_at' => Carbon::createFromTimestamp($expiresAt),
                ],
            ));
        }
    }

    /**
     * updateOrCreate that keeps the source created_at/updated_at instead of
     * letting Eloquent stamp fresh timestamps.
     */
    private function importPreservingTimestamps(Model $model, string $entity, array $keys, array $values): void
    {
        $record = $model->newQuery()->where($keys)->first() ?? $model->newInstance();
        $isNew = ! $record->exists;

        $record->forceFill([...$keys, ...$values]);
        $record->timestamps = false;
        $record->save();
        $record->timestamps = true;

        $this->track($entity, $record, $isNew);
    }

    // ------------------------------------------------------------------- utils

    private function tableMissing(Connection $source, string $table): bool
    {
        if ($source->getSchemaBuilder()->hasTable($table)) {
            return false;
        }

        $this->warn("Source table `{$table}` not found — skipping (older lejianwen version?).");

        return true;
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function track(string $entity, Model $model, ?bool $isNew = null): void
    {
        $isNew ??= $model->wasRecentlyCreated;

        if ($isNew) {
            $this->bump($entity, 'imported');
        } elseif ($model->wasChanged()) {
            $this->bump($entity, 'updated');
        } else {
            $this->bump($entity, 'updated', 0); // ensure the row shows up
        }
    }

    private function bump(string $entity, string $key, int $by = 1): void
    {
        $this->stats[$entity] ??= ['imported' => 0, 'updated' => 0, 'skipped' => 0];
        $this->stats[$entity][$key] += $by;
    }

    private function printSummary(bool $dryRun): void
    {
        $this->newLine();
        $this->info($dryRun
            ? 'DRY RUN — all changes were rolled back. Summary of what WOULD be imported:'
            : 'Import complete.');

        $order = [
            'user_groups', 'device_groups', 'users', 'address_books', 'tags',
            'address_book_entries', 'devices', 'audit_connections',
            'audit_file_transfers', 'login_logs', 'client_tokens',
        ];

        $rows = [];

        foreach ($order as $entity) {
            $s = $this->stats[$entity] ?? ['imported' => 0, 'updated' => 0, 'skipped' => 0];
            $rows[] = [$entity, $s['imported'], $s['updated'], $s['skipped']];
        }

        $this->table(['Entity', 'Imported', 'Updated', 'Skipped'], $rows);

        if ($this->skippedEmptyPeerIds > 0) {
            $this->warn("{$this->skippedEmptyPeerIds} peer(s) skipped (empty RustDesk id).");
        }

        if ($this->skippedEntryPasswords > 0) {
            $this->warn("{$this->skippedEntryPasswords} address book entry password(s) NOT imported (encrypted with the Go app's key; cannot be decrypted).");
        }

        if ($this->needsPasswordReset !== []) {
            $this->warn(count($this->needsPasswordReset).' user(s) imported with a random password (source hash was not bcrypt) — they need a password reset:');
            $this->line('  '.implode(', ', $this->needsPasswordReset));
        }
    }
}
