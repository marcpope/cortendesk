<?php

namespace App\Support;

use App\Models\AlarmLog;
use App\Models\ApiToken;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\ConsoleAudit;
use App\Models\LoginLog;
use App\Models\User;
use Closure;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Every audit record, also written as one JSON line to the log channel
 * `cortendesk_audit` (stderr by default), so a log shipper gets the audit
 * trail without polling the database or the API. See docs/audit-log-stream.md.
 *
 * Off unless CORTENDESK_AUDIT_LOG=true. The database stays the record of
 * truth. A line is written once its row is committed, so a rolled-back row
 * writes nothing, and a failure to write never fails the request.
 *
 * No passwords, hashes, tokens, device uuids, session guids or notes. A
 * failed sign-in with a name that matches no account leaves the name out
 * (people type passwords there). A line is never longer than MAX_LINE_BYTES.
 */
class AuditLogStream
{
    public const CHANNEL = 'cortendesk_audit';

    public const MARKER = 'cortendesk_audit';

    /** Longest string kept, in characters. */
    private const MAX_TEXT = 1000;

    /**
     * Longest line, in bytes, without its newline. php-fpm splits worker
     * output longer than its log_limit (8192 in the official image).
     */
    public const MAX_LINE_BYTES = 8000;

    private const CONNECTION_ACTIONS = ['new', 'authorized', 'close'];

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public static function enabled(): bool
    {
        return (bool) config('cortendesk.audit_log');
    }

    /** A console action: who did what (ConsoleAudit::record). */
    public static function consoleAudit(ConsoleAudit $row): void
    {
        self::write(function () use ($row) {
            $summary = (string) $row->summary;
            // Failed backups and restores carry the exception message.
            if (str_starts_with((string) $row->action, 'backup.') && str_contains($summary, ' failed: ')) {
                $summary = Str::before($summary, ' failed: ').' failed';
            }

            return self::record('console', (string) $row->action, $row->created_at, [
                'id' => $row->id,
                'user' => $row->username,
                'user_id' => $row->user_id,
                'target_type' => $row->target_type,
                'target_id' => $row->target_id,
                'summary' => $summary,
                'ip' => $row->ip,
            ]);
        });
    }

    /** A sign-in to the console or from a RustDesk client, successful or not. */
    public static function login(LoginLog $row): void
    {
        self::write(function () use ($row) {
            $name = self::accountName($row->successful || $row->user_id !== null, $row->username);

            return self::record('login', $row->successful ? 'login.success' : 'login.failure', $row->created_at, [
                'id' => $row->id,
                'user' => $name,
                'unknown_user' => $name === null && (string) $row->username !== '' ? true : null,
                'user_id' => $row->user_id,
                'client' => $row->client,
                'device' => $row->device_id,
                'device_os' => $row->device_os,
                'note' => $row->note,
                'ip' => $row->ip,
            ]);
        });
    }

    /** An alarm raised by a device (/api/audit/alarm) or by the console itself. */
    public static function alarm(AlarmLog $row): void
    {
        self::write(function () use ($row) {
            $info = $row->info;
            // Console alarms (brute force, spraying) hold the typed sign-in name.
            if ($row->rustdesk_id === AlarmLog::CONSOLE_SOURCE && is_array($decoded = json_decode((string) $info, true))) {
                foreach (['username', 'last_username'] as $key) {
                    if (isset($decoded[$key]) && self::accountName(false, $decoded[$key]) === null) {
                        unset($decoded[$key]);
                        $decoded['unknown_user'] = true;
                    }
                }
                $info = json_encode($decoded, self::JSON_FLAGS);
            }

            return self::record('alarm', 'alarm.'.$row->typ, $row->created_at, [
                'id' => $row->id,
                'device' => $row->rustdesk_id,
                'alarm_type' => $row->typ,
                'alarm' => $row->englishTypeLabel(),
                'conn_id' => $row->conn_id,
                'info' => $info,
            ]);
        });
    }

    /**
     * A remote session event: new, authorized or close (/api/audit/conn), or
     * a close the console inferred ($reason) when the device stopped reporting
     * it. `transport` is what is known when the line is written: the relay
     * link is made later, when hbbr reports the session, so it can be missing.
     */
    public static function connection(AuditConnection $row, ?string $reason = null, bool $created = false): void
    {
        self::write(function () use ($row, $reason, $created) {
            $known = in_array($row->action, self::CONNECTION_ACTIONS, true);
            $at = match (true) {
                $created => $row->created_at,
                $row->action === 'close' && $row->closed_at !== null => $row->closed_at,
                default => $row->updated_at,
            };

            return self::record('connection', 'connection.'.($known ? $row->action : 'unknown'), $at, [
                'id' => $row->id,
                'action' => $known ? null : $row->action,
                'device' => $row->rustdesk_id,
                'from_peer' => $row->from_peer,
                'from_name' => $row->from_name,
                'conn_type' => $row->conn_type,
                'conn_id' => $row->conn_id,
                'session_id' => $row->session_id,
                'path' => $row->path,
                'transport' => $row->transport(),
                'reason' => $reason,
                'ip' => $row->ip,
            ]);
        });
    }

    /** A file sent or received in a session (/api/audit/file). */
    public static function fileTransfer(AuditFileTransfer $row): void
    {
        self::write(fn () => self::record('file_transfer', 'file_transfer', $row->created_at, [
            'id' => $row->id,
            'device' => $row->rustdesk_id,
            'from_peer' => $row->from_peer,
            'from_name' => $row->from_name,
            'path' => $row->path,
            'is_file' => $row->is_file,
            'direction' => $row->direction,
            'file_count' => $row->file_count,
            'ip' => $row->ip,
        ]));
    }

    /**
     * An admin-API call with a bearer token (api-token-can): allowed, or
     * refused with $deniedStatus (401, 403).
     */
    public static function apiToken(Request $request, ?ApiToken $token, string $resource, string $level, ?int $deniedStatus = null): void
    {
        self::write(fn () => self::record('api_token', $deniedStatus === null ? 'api_token.use' : 'api_token.denied', now(), [
            'status' => $deniedStatus,
            'token_id' => $token?->id,
            'token_name' => $token?->name,
            'user' => $token?->user?->username,
            'user_id' => $token?->user_id,
            'resource' => $resource,
            'level' => $level,
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'ip' => $request->ip(),
        ]));
    }

    /** @param  Closure(): array<string, mixed>  $build */
    private static function write(Closure $build): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            $line = self::encode($build());
            DB::afterCommit(function () use ($line) {
                try {
                    self::useSchedulerDescriptor();
                    Log::channel(self::CHANNEL)->info($line);
                } catch (\Throwable) {
                    // A copy for the log shipper: never fail the request over it.
                }
            });
        } catch (\Throwable) {
            // Same.
        }
    }

    /**
     * Scheduled jobs run as `cmd > /dev/null 2>&1`, so in a job php://stderr
     * goes nowhere. The image's scheduler hands every job its own stderr as
     * an extra descriptor, named by CORTENDESK_AUDIT_LOG_FD
     * (docker/supervisord.conf). In the console, write there instead.
     */
    private static function useSchedulerDescriptor(): void
    {
        $key = 'logging.channels.'.self::CHANNEL.'.handler_with.stream';
        $fd = getenv('CORTENDESK_AUDIT_LOG_FD');

        if (PHP_SAPI !== 'cli' || config($key) !== 'php://stderr' || ! is_string($fd) || ! ctype_digit($fd)) {
            return;
        }

        // Open it rather than ask is_writable(): the pipe belongs to root
        // (supervisord), but the descriptor is ours to write.
        $handle = @fopen('php://fd/'.$fd, 'a');
        if ($handle !== false) {
            fclose($handle);
            config([$key => 'php://fd/'.$fd]);
            Log::forgetChannel(self::CHANNEL);
        }
    }

    /**
     * $at is the row's own time. A string when the model was saved without
     * timestamps (the importer keeps the source's).
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function record(string $source, string $event, DateTimeInterface|string|null $at, array $fields): array
    {
        return [
            self::MARKER => 1,
            'ts' => Carbon::parse($at ?? now())->utc()->format('Y-m-d\TH:i:s.up'),
            'source' => $source,
            'event' => $event,
        ] + array_filter($fields, fn ($v) => $v !== null && $v !== '');
    }

    /** One JSON line of at most MAX_LINE_BYTES: long strings are cut, longest first. */
    private static function encode(array $record): string
    {
        $record = array_map(fn ($v) => is_string($v) ? Str::limit($v, self::MAX_TEXT) : $v, $record);
        $line = json_encode($record, self::JSON_FLAGS);

        while (($over = strlen($line) - self::MAX_LINE_BYTES) > 0) {
            $strings = array_filter($record, 'is_string');
            uasort($strings, fn ($a, $b) => strlen($b) <=> strlen($a));
            $key = array_key_first($strings);
            if ($key === null || strlen($strings[$key]) <= 3) {
                break;
            }
            $value = $strings[$key];
            $record[$key] = mb_strcut($value, 0, max(0, strlen($value) - $over - 3), 'UTF-8').'...';
            $line = json_encode($record, self::JSON_FLAGS);
        }

        return $line;
    }

    /** $name when it is safe to log: a known account. Null when it may be a typo'd password. */
    private static function accountName(bool $known, mixed $name): ?string
    {
        if (! is_string($name) || $name === '') {
            return null;
        }

        return $known || User::where('username', $name)->exists() ? $name : null;
    }
}
