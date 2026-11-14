<?php

use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\ClientToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\LoginLog;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserGroup;
use App\Support\AuditLogStream;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

const LEJIANWEN_2A_HASH = '$2a$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';
const LEJIANWEN_2A_HASH_2 = '$2a$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ012345';

/**
 * Build a fixture lejianwen/rustdesk-api SQLite database matching the shapes
 * in docs/production-api-schema.sql. $mutate receives the PDO for extra rows,
 * $skipTables lets a test simulate an older lejianwen schema.
 */
function makeLejianwenDb(?Closure $mutate = null, array $skipTables = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'lejianwen_').'.db';
    $pdo = new PDO('sqlite:'.$path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $schema = [
        'users' => 'CREATE TABLE `users` (`id` integer PRIMARY KEY AUTOINCREMENT,`username` text NOT NULL DEFAULT "",`email` text NOT NULL DEFAULT "",`password` text NOT NULL DEFAULT "",`nickname` text NOT NULL DEFAULT "",`avatar` text NOT NULL DEFAULT "",`group_id` integer NOT NULL DEFAULT 0,`is_admin` numeric NOT NULL DEFAULT false,`status` integer NOT NULL DEFAULT 1,`remark` text NOT NULL DEFAULT "",`created_at` timestamp,`updated_at` timestamp)',
        'groups' => 'CREATE TABLE `groups` (`id` integer PRIMARY KEY AUTOINCREMENT,`name` text NOT NULL DEFAULT "",`type` integer NOT NULL DEFAULT 1,`created_at` timestamp,`updated_at` timestamp)',
        'device_groups' => 'CREATE TABLE `device_groups` (`id` integer PRIMARY KEY AUTOINCREMENT,`name` text NOT NULL DEFAULT "",`created_at` timestamp,`updated_at` timestamp)',
        'peers' => 'CREATE TABLE `peers` (`row_id` integer PRIMARY KEY AUTOINCREMENT,`id` text NOT NULL DEFAULT "",`cpu` text NOT NULL DEFAULT "",`hostname` text NOT NULL DEFAULT "",`memory` text NOT NULL DEFAULT "",`os` text NOT NULL DEFAULT "",`username` text NOT NULL DEFAULT "",`uuid` text NOT NULL DEFAULT "",`version` text NOT NULL DEFAULT "",`user_id` integer NOT NULL DEFAULT 0,`last_online_time` integer NOT NULL DEFAULT 0,`last_online_ip` text NOT NULL DEFAULT "",`group_id` integer NOT NULL DEFAULT 0,`alias` text NOT NULL DEFAULT "",`created_at` timestamp,`updated_at` timestamp)',
        'address_book_collections' => 'CREATE TABLE `address_book_collections` (`id` integer PRIMARY KEY AUTOINCREMENT,`user_id` integer NOT NULL DEFAULT 0,`name` text NOT NULL DEFAULT "",`created_at` timestamp,`updated_at` timestamp)',
        'tags' => 'CREATE TABLE `tags` (`id` integer PRIMARY KEY AUTOINCREMENT,`name` text NOT NULL DEFAULT "",`user_id` integer NOT NULL DEFAULT 0,`color` integer NOT NULL DEFAULT 0,`collection_id` integer NOT NULL DEFAULT 0,`created_at` timestamp,`updated_at` timestamp)',
        'address_books' => 'CREATE TABLE `address_books` (`row_id` integer PRIMARY KEY AUTOINCREMENT,`id` text NOT NULL DEFAULT "0",`username` text NOT NULL DEFAULT "",`password` text NOT NULL DEFAULT "",`hostname` text NOT NULL DEFAULT "",`alias` text NOT NULL DEFAULT "",`platform` text NOT NULL DEFAULT "",`tags` text NOT NULL,`hash` text NOT NULL DEFAULT "",`user_id` integer NOT NULL DEFAULT 0,`force_always_relay` numeric NOT NULL DEFAULT false,`rdp_port` text NOT NULL DEFAULT "",`rdp_username` text NOT NULL DEFAULT "",`online` numeric NOT NULL DEFAULT false,`login_name` text NOT NULL DEFAULT "",`same_server` numeric NOT NULL DEFAULT false,`collection_id` integer NOT NULL DEFAULT 0,`created_at` timestamp,`updated_at` timestamp)',
        'audit_conns' => 'CREATE TABLE `audit_conns` (`id` integer PRIMARY KEY AUTOINCREMENT,`action` text NOT NULL DEFAULT "",`conn_id` integer NOT NULL DEFAULT 0,`peer_id` text NOT NULL DEFAULT "",`from_peer` text NOT NULL DEFAULT "",`from_name` text NOT NULL DEFAULT "",`ip` text NOT NULL DEFAULT "",`session_id` text NOT NULL DEFAULT "",`type` integer NOT NULL DEFAULT 0,`uuid` text NOT NULL DEFAULT "",`close_time` integer NOT NULL DEFAULT 0,`created_at` timestamp,`updated_at` timestamp)',
        'audit_files' => 'CREATE TABLE `audit_files` (`id` integer PRIMARY KEY AUTOINCREMENT,`from_peer` text NOT NULL DEFAULT "",`info` text NOT NULL DEFAULT "",`is_file` numeric NOT NULL DEFAULT false,`path` text NOT NULL DEFAULT "",`peer_id` text NOT NULL DEFAULT "",`type` integer NOT NULL DEFAULT 0,`uuid` text NOT NULL DEFAULT "",`ip` text NOT NULL DEFAULT "",`num` integer NOT NULL DEFAULT 0,`from_name` text NOT NULL DEFAULT "",`created_at` timestamp,`updated_at` timestamp)',
        'login_logs' => 'CREATE TABLE `login_logs` (`id` integer PRIMARY KEY AUTOINCREMENT,`user_id` integer NOT NULL DEFAULT 0,`client` text,`device_id` text,`uuid` text,`ip` text,`type` text,`platform` text,`user_token_id` integer NOT NULL DEFAULT 0,`is_deleted` integer NOT NULL DEFAULT 0,`created_at` timestamp,`updated_at` timestamp)',
        'user_tokens' => 'CREATE TABLE `user_tokens` (`id` integer PRIMARY KEY AUTOINCREMENT,`user_id` integer NOT NULL DEFAULT 0,`device_uuid` text DEFAULT "",`device_id` text DEFAULT "",`token` text NOT NULL DEFAULT "",`expired_at` integer NOT NULL DEFAULT 0,`created_at` timestamp,`updated_at` timestamp)',
    ];

    foreach ($schema as $table => $ddl) {
        if (! in_array($table, $skipTables, true)) {
            $pdo->exec($ddl);
        }
    }

    $seed = [
        "INSERT INTO `groups` (id, name, type) VALUES (1, 'Staff', 1)",
        "INSERT INTO `device_groups` (id, name) VALUES (1, 'Office')",

        "INSERT INTO users (id, username, email, password, nickname, is_admin, status, group_id, remark)
            VALUES (1, 'alice', 'alice@example.com', '".LEJIANWEN_2A_HASH."', 'Alice A', 1, 1, 1, 'the boss')",
        "INSERT INTO users (id, username, email, password, nickname, is_admin, status, group_id, remark)
            VALUES (2, 'bob', '', '".LEJIANWEN_2A_HASH_2."', 'Bob B', 0, 0, 0, '')",

        "INSERT INTO peers (id, uuid, hostname, os, cpu, memory, username, version, alias, user_id, group_id, last_online_time, last_online_ip)
            VALUES ('111111111', 'uuid-1', 'ALICE-PC', 'Windows 11', 'i7', '16GB', 'alice', '1.3.2', 'Alice PC', 1, 1, 1720000000, '10.0.0.5')",
        "INSERT INTO peers (id, uuid, hostname, os, user_id, group_id, last_online_time)
            VALUES ('222222222', 'uuid-2', 'BOB-MAC', 'Mac OS 14', 2, 0, 0)",
        "INSERT INTO peers (id, uuid, hostname, os, user_id, group_id, last_online_time)
            VALUES ('333333333', 'uuid-3', 'ORPHAN', 'Ubuntu 22.04', 0, 99, 1719990000)",

        "INSERT INTO address_book_collections (id, user_id, name) VALUES (1, 1, 'Shared Servers')",

        "INSERT INTO tags (id, name, user_id, color, collection_id) VALUES (1, 'Servers', 1, 4278190080, 0)",
        "INSERT INTO tags (id, name, user_id, color, collection_id) VALUES (2, 'Prod', 1, 4294901760, 1)",

        "INSERT INTO address_books (id, username, password, hostname, alias, platform, tags, hash, user_id, force_always_relay, rdp_port, rdp_username, login_name, collection_id)
            VALUES ('111111111', 'alice', '', 'ALICE-PC', 'Alice PC', 'Windows', '[\"Servers\"]', 'client-side-hash-abc', 1, 0, '', '', 'alice', 0)",
        "INSERT INTO address_books (id, username, password, hostname, alias, platform, tags, user_id, force_always_relay, rdp_port, rdp_username, login_name, collection_id)
            VALUES ('222222222', 'bob', 'ENCRYPTED-BLOB==', 'BOB-MAC', 'Bob Mac', 'Mac OS', '[\"Prod\"]', 1, 1, '3389', 'administrator', '', 1)",
        "INSERT INTO address_books (id, username, password, hostname, alias, platform, tags, user_id, collection_id)
            VALUES ('333333333', '', '', 'ORPHAN', '', 'Linux', '[]', 2, 0)",

        "INSERT INTO audit_conns (action, conn_id, peer_id, from_peer, from_name, ip, session_id, type, uuid, close_time, created_at, updated_at)
            VALUES ('new', 10, '111111111', '999999999', 'tech-laptop', '10.0.0.9', 'sess-1', 1, 'conn-uuid-1', 1720000100, '2024-05-01 10:00:00', '2024-05-01 10:05:00')",
        "INSERT INTO audit_conns (action, conn_id, peer_id, from_peer, from_name, ip, session_id, type, uuid, close_time, created_at, updated_at)
            VALUES ('new', 11, '222222222', '999999999', 'tech-laptop', '10.0.0.9', 'sess-2', 0, 'conn-uuid-2', 0, '2024-05-02 11:00:00', '2024-05-02 11:00:00')",

        "INSERT INTO audit_files (from_peer, info, is_file, path, peer_id, type, uuid, ip, num, from_name, created_at, updated_at)
            VALUES ('999999999', '{}', 1, '/tmp/report.pdf', '111111111', 0, 'file-uuid-1', '10.0.0.9', 3, 'tech-laptop', '2024-05-03 09:00:00', '2024-05-03 09:00:00')",

        "INSERT INTO login_logs (user_id, client, device_id, uuid, ip, type, platform, created_at, updated_at)
            VALUES (1, 'webadmin', 'dev-1', 'll-uuid-1', '10.0.0.9', 'account', 'windows', '2024-05-04 08:00:00', '2024-05-04 08:00:00')",
        "INSERT INTO login_logs (user_id, client, device_id, uuid, ip, type, platform, created_at, updated_at)
            VALUES (99, 'client', 'dev-2', 'll-uuid-2', '10.0.0.10', 'account', 'linux', '2024-05-04 09:00:00', '2024-05-04 09:00:00')",
    ];

    foreach ($seed as $sql) {
        [$table] = sscanf(ltrim($sql), 'INSERT INTO %s');
        $table = trim($table, '`');

        if (! in_array($table, $skipTables, true)) {
            $pdo->exec($sql);
        }
    }

    if ($mutate) {
        $mutate($pdo);
    }

    return $path;
}

it('imports a full lejianwen database', function () {
    $db = makeLejianwenDb();

    $this->artisan('cortendesk:import-lejianwen', ['path' => $db])->assertSuccessful();

    // Counts
    expect(User::count())->toBe(2)
        ->and(UserGroup::count())->toBe(1)
        ->and(DeviceGroup::count())->toBe(1)
        ->and(Device::count())->toBe(3)
        ->and(AddressBook::count())->toBe(3) // 2 personal + 1 shared
        ->and(Tag::count())->toBe(2)
        ->and(AddressBookEntry::count())->toBe(3)
        ->and(AuditConnection::count())->toBe(2)
        ->and(AuditFileTransfer::count())->toBe(1)
        ->and(LoginLog::count())->toBe(2);

    // Users
    $alice = User::where('username', 'alice')->firstOrFail();
    $bob = User::where('username', 'bob')->firstOrFail();
    expect($alice->name)->toBe('Alice A')
        ->and($alice->email)->toBe('alice@example.com')
        ->and($alice->is_admin)->toBeTrue()
        ->and($alice->is_active)->toBeTrue()
        ->and($alice->note)->toBe('the boss')
        ->and($alice->groups->pluck('name')->all())->toBe(['Staff'])
        ->and($bob->email)->toBeNull()
        ->and($bob->is_active)->toBeFalse()
        ->and($bob->groups)->toBeEmpty();

    // Devices
    $dev1 = Device::where('rustdesk_id', '111111111')->firstOrFail();
    $dev2 = Device::where('rustdesk_id', '222222222')->firstOrFail();
    $dev3 = Device::where('rustdesk_id', '333333333')->firstOrFail();
    expect($dev1->user_id)->toBe($alice->id)
        ->and($dev1->device_group_id)->toBe(DeviceGroup::where('name', 'Office')->value('id'))
        ->and($dev1->last_online_at->timestamp)->toBe(1720000000)
        ->and($dev1->last_online_ip)->toBe('10.0.0.5')
        ->and($dev1->hostname)->toBe('ALICE-PC')
        ->and($dev2->user_id)->toBe($bob->id)
        ->and($dev2->last_online_at)->toBeNull() // 0 = never
        ->and($dev3->user_id)->toBeNull()        // unknown source user 0
        ->and($dev3->device_group_id)->toBeNull(); // unknown source group 99

    // Audit connections
    $conn = AuditConnection::where('conn_id', 10)->firstOrFail();
    expect($conn->rustdesk_id)->toBe('111111111')
        ->and($conn->conn_type)->toBe(1)
        ->and($conn->closed_at->timestamp)->toBe(1720000100)
        ->and($conn->created_at->format('Y-m-d H:i:s'))->toBe('2024-05-01 10:00:00');
    expect(AuditConnection::where('conn_id', 11)->firstOrFail()->closed_at)->toBeNull();

    // File transfers
    $ft = AuditFileTransfer::firstOrFail();
    expect($ft->rustdesk_id)->toBe('111111111')
        ->and($ft->direction)->toBe(0)
        ->and($ft->file_count)->toBe(3)
        ->and($ft->path)->toBe('/tmp/report.pdf');

    // Login logs: source has no username column, resolved from user map
    expect(LoginLog::where('device_id', 'dev-1')->firstOrFail()->username)->toBe('alice')
        ->and(LoginLog::where('device_id', 'dev-2')->firstOrFail()->username)->toBe('unknown')
        ->and(LoginLog::where('device_id', 'dev-2')->firstOrFail()->user_id)->toBeNull();
});

it('imports Go bcrypt hashes normalized to $2y$ so Laravel logins verify', function () {
    // A genuine $2a$ bcrypt hash of a known password, as Go's bcrypt produces.
    $salt = '$2a$10$'.substr(strtr(base64_encode(random_bytes(16)), '+', '.'), 0, 22);
    $goHash = crypt('secret123', $salt);
    expect($goHash)->toStartWith('$2a$10$');

    $db = makeLejianwenDb(function (PDO $pdo) use ($goHash) {
        $pdo->exec("INSERT INTO users (id, username, email, password, nickname, is_admin, status, group_id)
            VALUES (7, 'gopher', '', '$goHash', 'Go User', 0, 1, 0)");
    });

    $this->artisan('cortendesk:import-lejianwen', ['path' => $db])->assertSuccessful();

    $stored = DB::table('users')->where('username', 'gopher')->value('password');

    // Prefix rewritten $2a$ → $2y$ (Laravel's BcryptHasher throws on $2a$),
    // salt + digest byte-identical.
    expect($stored)->toBe('$2y$'.substr($goHash, 4));

    // The imported credential must pass Laravel's own hasher end to end —
    // this is exactly the code path console + client logins use.
    expect(Hash::check('secret123', $stored))->toBeTrue()
        ->and(Hash::check('wrong', $stored))->toBeFalse();

    // The fixture's stock users normalize the same way.
    expect(DB::table('users')->where('username', 'alice')->value('password'))
        ->toBe('$2y$'.substr(LEJIANWEN_2A_HASH, 4));
});

it('assigns a random password and reports users whose source hash is not bcrypt', function () {
    $db = makeLejianwenDb(function (PDO $pdo) {
        $pdo->exec("INSERT INTO users (id, username, email, password, nickname, is_admin, status, group_id)
            VALUES (3, 'legacy', '', 'e10adc3949ba59abbe56e057f20f883e', 'Legacy', 0, 1, 0)");
    });

    $this->artisan('cortendesk:import-lejianwen', ['path' => $db])
        ->expectsOutputToContain('legacy')
        ->assertSuccessful();

    $stored = DB::table('users')->where('username', 'legacy')->value('password');
    expect($stored)->not->toBe('e10adc3949ba59abbe56e057f20f883e')
        ->and($stored)->toStartWith('$2');
});

it('resolves entry tag names to tag ids within the right address book', function () {
    $db = makeLejianwenDb();

    $this->artisan('cortendesk:import-lejianwen', ['path' => $db])->assertSuccessful();

    $alice = User::where('username', 'alice')->firstOrFail();
    $personal = AddressBook::where('owner_user_id', $alice->id)->where('is_personal', true)->firstOrFail();
    $shared = AddressBook::where('is_personal', false)->firstOrFail();

    $serversTag = Tag::where('name', 'Servers')->firstOrFail();
    $prodTag = Tag::where('name', 'Prod')->firstOrFail();

    // Tags landed in the right books (collection 0 => personal, 1 => shared)
    expect($serversTag->address_book_id)->toBe($personal->id)
        ->and($serversTag->color)->toBe(4278190080)
        ->and($prodTag->address_book_id)->toBe($shared->id);

    // Entries reference tags by our new ids, in the same book
    $entry1 = AddressBookEntry::where('rustdesk_id', '111111111')->firstOrFail();
    $entry2 = AddressBookEntry::where('rustdesk_id', '222222222')->firstOrFail();
    expect($entry1->address_book_id)->toBe($personal->id)
        ->and($entry1->tag_ids)->toBe([$serversTag->id])
        ->and($entry2->address_book_id)->toBe($shared->id)
        ->and($entry2->tag_ids)->toBe([$prodTag->id]);
});

it('places entries into personal vs shared books and skips encrypted passwords', function () {
    $db = makeLejianwenDb();

    $this->artisan('cortendesk:import-lejianwen', ['path' => $db])
        ->expectsOutputToContain('1 address book entry password(s) NOT imported')
        ->assertSuccessful();

    $alice = User::where('username', 'alice')->firstOrFail();
    $bob = User::where('username', 'bob')->firstOrFail();

    $shared = AddressBook::where('is_personal', false)->firstOrFail();
    expect($shared->name)->toBe('Shared Servers')
        ->and($shared->owner_user_id)->toBe($alice->id);

    $bobPersonal = AddressBook::where('owner_user_id', $bob->id)->where('is_personal', true)->firstOrFail();
    $entry3 = AddressBookEntry::where('rustdesk_id', '333333333')->firstOrFail();
    expect($entry3->address_book_id)->toBe($bobPersonal->id);

    // Client-side password hash (portable) is carried over
    $entry1 = AddressBookEntry::where('rustdesk_id', '111111111')->firstOrFail();
    expect($entry1->getRawOriginal('hash'))->toBe('client-side-hash-abc');

    // Encrypted stored password never lands in our schema
    $entry2 = AddressBookEntry::where('rustdesk_id', '222222222')->firstOrFail();
    expect($entry2->password_enc)->toBeNull()
        ->and($entry2->rdp_port)->toBe('3389')
        ->and($entry2->rdp_username)->toBe('administrator')
        ->and($entry2->force_always_relay)->toBeTrue();
});

it('is idempotent: running twice creates no duplicates and keeps hashes intact', function () {
    $db = makeLejianwenDb();

    $this->artisan('cortendesk:import-lejianwen', ['path' => $db])->assertSuccessful();
    $this->artisan('cortendesk:import-lejianwen', ['path' => $db])->assertSuccessful();

    expect(User::count())->toBe(2)
        ->and(UserGroup::count())->toBe(1)
        ->and(DeviceGroup::count())->toBe(1)
        ->and(Device::count())->toBe(3)
        ->and(AddressBook::count())->toBe(3)
        ->and(Tag::count())->toBe(2)
        ->and(AddressBookEntry::count())->toBe(3)
        ->and(AuditConnection::count())->toBe(2)
        ->and(AuditFileTransfer::count())->toBe(1)
        ->and(LoginLog::count())->toBe(2)
        ->and(DB::table('users')->where('username', 'alice')->value('password'))->toBe('$2y$'.substr(LEJIANWEN_2A_HASH, 4));
});

it('rolls back everything under --dry-run but still reports', function () {
    $db = makeLejianwenDb();

    $this->artisan('cortendesk:import-lejianwen', ['path' => $db, '--dry-run' => true])
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    expect(User::count())->toBe(0)
        ->and(UserGroup::count())->toBe(0)
        ->and(DeviceGroup::count())->toBe(0)
        ->and(Device::count())->toBe(0)
        ->and(AddressBook::count())->toBe(0)
        ->and(Tag::count())->toBe(0)
        ->and(AddressBookEntry::count())->toBe(0)
        ->and(AuditConnection::count())->toBe(0)
        ->and(AuditFileTransfer::count())->toBe(0)
        ->and(LoginLog::count())->toBe(0);
});

it('writes no audit log stream lines under --dry-run, and timestamps imported history by its own time', function () {
    $file = tempnam(sys_get_temp_dir(), 'audit');
    config(['cortendesk.audit_log' => true, 'logging.channels.cortendesk_audit.handler_with.stream' => $file]);
    Log::forgetChannel(AuditLogStream::CHANNEL);
    $db = makeLejianwenDb();

    $this->artisan('cortendesk:import-lejianwen', ['path' => $db, '--dry-run' => true])->assertSuccessful();
    expect(file_get_contents($file))->toBe('');

    $this->artisan('cortendesk:import-lejianwen', ['path' => $db])->assertSuccessful();
    $lines = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", file_get_contents($file))));
    $conns = array_values(array_filter($lines, fn ($l) => $l['source'] === 'connection'));

    expect($conns)->not->toBeEmpty()
        ->and($conns[0]['ts'])->toStartWith('2024-05-01T10:00:00');
});

it('skips peers with an empty rustdesk id and reports the count', function () {
    $db = makeLejianwenDb(function (PDO $pdo) {
        $pdo->exec("INSERT INTO peers (id, uuid, hostname, user_id) VALUES ('', 'uuid-empty', 'GHOST', 1)");
    });

    $this->artisan('cortendesk:import-lejianwen', ['path' => $db])
        ->expectsOutputToContain('1 peer(s) skipped (empty RustDesk id)')
        ->assertSuccessful();

    expect(Device::count())->toBe(3);
});

it('warns and continues when source tables are missing (older lejianwen)', function () {
    $db = makeLejianwenDb(skipTables: ['audit_files', 'login_logs', 'device_groups']);

    $this->artisan('cortendesk:import-lejianwen', ['path' => $db])
        ->expectsOutputToContain('audit_files')
        ->assertSuccessful();

    expect(User::count())->toBe(2)
        ->and(Device::count())->toBe(3)
        ->and(AuditFileTransfer::count())->toBe(0)
        ->and(LoginLog::count())->toBe(0);
});

it('wipes target tables first with --wipe', function () {
    User::create([
        'username' => 'stale',
        'password' => 'irrelevant-password',
        'is_admin' => false,
        'is_active' => true,
    ]);
    Device::create(['rustdesk_id' => '999999999', 'uuid' => 'stale-uuid']);

    $db = makeLejianwenDb();

    $this->artisan('cortendesk:import-lejianwen', ['path' => $db, '--wipe' => true])->assertSuccessful();

    expect(User::where('username', 'stale')->exists())->toBeFalse()
        ->and(Device::where('rustdesk_id', '999999999')->exists())->toBeFalse()
        ->and(User::count())->toBe(2)
        ->and(Device::count())->toBe(3);
});

it('carries over unexpired client session tokens and drops expired ones', function () {
    $path = makeLejianwenDb(function (PDO $pdo) {
        $live = time() + 86400;
        $dead = time() - 3600;
        $pdo->exec("INSERT INTO user_tokens (user_id, device_uuid, device_id, token, expired_at) VALUES
            (1, 'uuid-a', '123456789', 'live-token-abc', $live),
            (1, 'uuid-b', '987654321', 'dead-token-xyz', $dead),
            (999, 'uuid-c', '555', 'orphan-token', $live)");
    });

    $this->artisan('cortendesk:import-lejianwen', ['path' => $path])->assertSuccessful();

    // The live token survives verbatim, bound to the remapped user...
    $token = ClientToken::where('token', 'live-token-abc')->first();
    expect($token)->not->toBeNull()
        ->and($token->device_id)->toBe('123456789')
        ->and($token->isValid())->toBeTrue()
        ->and($token->user->username)->toBe('alice');

    // ...while expired and orphaned (unknown user) tokens are dropped.
    expect(ClientToken::where('token', 'dead-token-xyz')->exists())->toBeFalse()
        ->and(ClientToken::where('token', 'orphan-token')->exists())->toBeFalse();
});
