<?php

namespace App\Support;

use App\Models\AlarmLog;
use App\Models\ApiToken;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\ConsoleAudit;
use App\Models\LoginLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Every audit record, also written as one JSON line to the log channel
 * `cortendesk_audit` (stderr by default), so a log shipper (Loki, Elastic,
 * a SIEM) gets the audit trail without polling the database or the API.
 *
 * Off unless CORTENDESK_AUDIT_LOG_STDOUT=true. The database stays the record
 * of truth; the stream is a copy, written after the row is saved, and a
 * failure to write it never fails the request.
 *
 * Each line starts with {"cortendesk_audit":1, so a shipper can tell it from
 * everything else on the container's output. Fields are the row's own
 * columns, picked by hand: no passwords, hashes, tokens or token prefixes,
 * device uuids or free-text session notes. Long free text is cut at 1000
 * characters.
 */
class AuditLogStream
{
    public const CHANNEL = 'cortendesk_audit';

    public const MARKER = 'cortendesk_audit';

    private const MAX_TEXT = 1000;

    public static function enabled(): bool
    {
        return (bool) config('cortendesk.audit_log_stdout');
    }

    /** A console action: who did what (ConsoleAudit::record). */
    public static function consoleAudit(ConsoleAudit $row): void
    {
        self::write('console', (string) $row->action, [
            'id' => $row->id,
            'user' => $row->username,
            'user_id' => $row->user_id,
            'target_type' => $row->target_type,
            'target_id' => $row->target_id,
            'summary' => self::text($row->summary),
            'ip' => $row->ip,
        ]);
    }

    /** A sign-in to the console or from a RustDesk client, successful or not. */
    public static function login(LoginLog $row): void
    {
        self::write('login', $row->successful ? 'login.success' : 'login.failure', [
            'id' => $row->id,
            'user' => $row->username,
            'user_id' => $row->user_id,
            'client' => $row->client,
            'device' => $row->device_id,
            'device_os' => $row->device_os,
            'note' => self::text($row->note),
            'ip' => $row->ip,
        ]);
    }

    /** An alarm raised by a device (/api/audit/alarm) or by the console itself. */
    public static function alarm(AlarmLog $row): void
    {
        self::write('alarm', 'alarm.'.$row->typ, [
            'id' => $row->id,
            'device' => $row->rustdesk_id,
            'alarm_type' => $row->typ,
            'alarm' => $row->englishTypeLabel(),
            'conn_id' => $row->conn_id,
            'info' => self::text($row->info),
        ]);
    }

    /**
     * A remote session event: new, authorized or close (/api/audit/conn), or
     * a close the console inferred ($reason) when the device stopped reporting it.
     */
    public static function connection(AuditConnection $row, ?string $reason = null): void
    {
        self::write('connection', 'connection.'.$row->action, [
            'id' => $row->id,
            'device' => $row->rustdesk_id,
            'from_peer' => $row->from_peer,
            'from_name' => $row->from_name,
            'conn_type' => $row->conn_type,
            'conn_id' => $row->conn_id,
            'session_id' => $row->session_id,
            'guid' => $row->guid,
            'reason' => $reason,
            'ip' => $row->ip,
        ]);
    }

    /** A file sent or received in a session (/api/audit/file). */
    public static function fileTransfer(AuditFileTransfer $row): void
    {
        self::write('file_transfer', 'file_transfer', [
            'id' => $row->id,
            'device' => $row->rustdesk_id,
            'from_peer' => $row->from_peer,
            'from_name' => $row->from_name,
            'path' => self::text($row->path),
            'is_file' => $row->is_file,
            'direction' => $row->direction,
            'file_count' => $row->file_count,
            'ip' => $row->ip,
        ]);
    }

    /** An admin-API call with a bearer token, allowed or refused (api-token-can). */
    public static function apiToken(Request $request, ?ApiToken $token, string $resource, string $level, bool $allowed): void
    {
        self::write('api_token', $allowed ? 'api_token.use' : 'api_token.denied', [
            'token_id' => $token?->id,
            'token_name' => $token?->name,
            'user' => $token?->user?->username,
            'user_id' => $token?->user_id,
            'resource' => $resource,
            'level' => $level,
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'ip' => $request->ip(),
        ]);
    }

    /** @param  array<string, mixed>  $fields */
    private static function write(string $source, string $event, array $fields): void
    {
        if (! self::enabled()) {
            return;
        }

        $record = [
            self::MARKER => 1,
            'ts' => now()->utc()->format('Y-m-d\TH:i:s.up'),
            'source' => $source,
            'event' => $event,
        ] + array_filter($fields, fn ($v) => $v !== null && $v !== '');

        try {
            Log::channel(self::CHANNEL)->info((string) json_encode(
                $record,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            ));
        } catch (\Throwable) {
            // A copy for the log shipper: never fail the request over it.
        }
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : Str::limit((string) $value, self::MAX_TEXT);
    }
}
