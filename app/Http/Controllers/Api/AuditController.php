<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlarmLog;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\ClientToken;
use App\Models\Device;
use App\Services\AppriseNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class AuditController extends Controller
{
    /**
     * How long a record's nonce is remembered. The client stops retrying
     * 120 seconds after the first attempt; this is the window its retry
     * logic is written against (docs/client-api.md §21).
     */
    public const NONCE_TTL_SECONDS = 300;

    /** A "new" for the same connection inside this window is a retry. */
    public const RETRY_WINDOW_SECONDS = 120;

    /**
     * POST /api/audit/conn — spec §21. Three shapes from the controlled side:
     * 1. {"action":"new", ...}            connection opened (pre-auth)
     * 2. {"peer":[id,name], "type":N, …}  connection authorized (no action key)
     * 3. {"action":"close", ...}          connection closed
     *
     * Plus one from the controlling side: {"id", "session_id", "note"}, the
     * in-session Note button (see legacyNote()).
     *
     * "new" is posted before the peer's LoginRequest arrives, so its
     * session_id is always 0. The real one comes with "authorized".
     */
    public function connection(Request $request): Response|JsonResponse
    {
        $action = $request->input('action');
        $id = (string) $request->input('id', '');
        $connId = (int) $request->input('conn_id', 0);
        $sessionId = $this->sessionId($request);

        if ($request->has('note') && ! $request->has('conn_id')) {
            return $this->once($request, 'note', $id, fn () => $this->legacyNote($id, $sessionId, $request->input('note')));
        }

        if ($id === '' || $connId === 0) {
            return $this->stored();
        }

        return $this->once($request, 'conn', $id, function () use ($request, $action, $id, $connId, $sessionId) {
            if ($action === 'new') {
                if (! $this->isRetriedNew($id, $connId)) {
                    AuditConnection::create([
                        'action' => 'new',
                        'conn_id' => $connId,
                        'rustdesk_id' => $id,
                        'ip' => (string) $request->input('ip', $request->ip()),
                        'session_id' => $sessionId,
                        'uuid' => (string) $request->input('uuid', ''),
                    ]);
                }

                return;
            }

            $open = AuditConnection::where('rustdesk_id', $id)
                ->where('conn_id', $connId)
                ->whereNull('closed_at')
                ->latest('id')
                ->first();

            if ($action === 'close') {
                $open?->update(['action' => 'close', 'closed_at' => now()]);

                return;
            }

            // Authorized: no action key; peer is a 2-element [id, name] array.
            $peer = (array) $request->input('peer', []);
            $attributes = [
                'action' => 'authorized',
                'from_peer' => (string) ($peer[0] ?? ''),
                'from_name' => (string) ($peer[1] ?? ''),
                'conn_type' => (int) $request->input('type', 0),
            ];

            // The controlling client looks its session up by this value.
            if ($sessionId !== '') {
                $attributes['session_id'] = $sessionId;
            }

            if ($open !== null) {
                $open->update($attributes);
            } else {
                AuditConnection::create($attributes + [
                    'conn_id' => $connId,
                    'rustdesk_id' => $id,
                    'session_id' => $sessionId,
                    'uuid' => (string) $request->input('uuid', ''),
                    'ip' => $request->ip(),
                ]);
            }
        });
    }

    /** POST /api/audit/file — spec §21. `info` is a JSON-encoded string. */
    public function file(Request $request): Response|JsonResponse
    {
        $id = (string) $request->input('id', '');
        if ($id === '') {
            return $this->stored();
        }

        $info = (string) $request->input('info', '');
        $decoded = json_decode($info, true);
        $decoded = is_array($decoded) ? $decoded : [];

        return $this->once($request, 'file', $id, fn () => AuditFileTransfer::create([
            'rustdesk_id' => $id,
            'from_peer' => (string) $request->input('peer_id', ''),
            'from_name' => (string) ($decoded['name'] ?? ''),
            'path' => (string) $request->input('path', ''),
            'info' => $info,
            'is_file' => (bool) $request->input('is_file', false),
            'direction' => (int) $request->input('type', 0),
            'file_count' => (int) ($decoded['num'] ?? 0),
            'ip' => (string) ($decoded['ip'] ?? $request->ip()),
            'uuid' => (string) $request->input('uuid', ''),
        ]));
    }

    /** POST /api/audit/alarm — spec §21. */
    public function alarm(Request $request): Response|JsonResponse
    {
        $id = (string) $request->input('id', '');
        if ($id === '') {
            return $this->stored();
        }

        $alarm = null;
        $response = $this->once($request, 'alarm', $id, function () use ($request, $id, &$alarm) {
            $alarm = AlarmLog::create([
                'rustdesk_id' => $id,
                'uuid' => (string) $request->input('uuid', ''),
                'typ' => (int) $request->input('typ', 0),
                'info' => (string) $request->input('info', ''),
                'conn_id' => (int) $request->input('conn_id', 0) ?: null,
            ]);
        });

        // A retry we already stored, or a write that failed: nothing to announce.
        if ($alarm === null) {
            return $response;
        }

        $notifications = app(AppriseNotifications::class);
        $device = Device::query()->where('rustdesk_id', $id)->first();
        $notifications->sendAfterResponse(
            'security.alarm',
            $alarm->typeLabel(),
            'Device '.$id.' reported a security alarm.',
            'alarm:'.$alarm->id,
            $device,
        );

        if ($alarm->typ === 1) {
            $notifications->sendAfterResponse(
                'remote_connection.failure',
                'Repeated remote connection failures',
                'Device '.$id.' reported more than 30 failed connection attempts.',
                'device:'.$id,
                $device,
            );
        }

        return $response;
    }

    /**
     * GET /api/audit/conn/active?id=&session_id=&conn_type= (Bearer) — the
     * controlling client asks for its session's guid once the peer info
     * arrives, then sends the end-of-session note against it.
     *
     * Always 200 with a JSON string. The client retries for about 9 seconds
     * while the answer is "" (the controlled side posts "authorized" at about
     * the same moment) and gives up on the first non-200.
     *
     * Only the device that opened the session gets its guid: the row's
     * from_peer must be the device id the bearer token signed in from.
     */
    public function activeConnection(Request $request): JsonResponse
    {
        $id = (string) $request->query('id', '');
        $sessionId = (string) $request->query('session_id', '');
        $device = $this->tokenDevice($request);

        if ($id === '' || $sessionId === '' || $sessionId === '0' || $device === '') {
            return response()->json('');
        }

        $connType = $request->query('conn_type');

        $row = AuditConnection::query()
            ->where('rustdesk_id', $id)
            ->where('session_id', $sessionId)
            ->where('from_peer', $device)
            ->when(is_numeric($connType), fn ($q) => $q->where('conn_type', (int) $connType))
            ->latest('id')
            ->first();

        if ($row === null) {
            return response()->json('');
        }

        // Rows recorded before guids existed get one on first request.
        if ($row->guid === null) {
            $row->update(['guid' => (string) Str::uuid()]);
        }

        return response()->json($row->guid);
    }

    /**
     * PUT /api/audit {guid, note} (Bearer) — spec §21: the end-of-session
     * note. The client only logs the status.
     *
     * Same rule as activeConnection(): the token's device must be the one
     * that opened the session. A note another user already wrote is not
     * overwritten.
     */
    public function note(Request $request): JsonResponse
    {
        $guid = (string) $request->input('guid', '');
        $note = $this->cleanNote($request->input('note'));

        if ($guid === '' || $note === null) {
            return response()->json((object) []);
        }

        $row = AuditConnection::where('guid', $guid)->first();
        if ($row === null) {
            return response()->json(['error' => 'Unknown session'], 404);
        }

        $device = $this->tokenDevice($request);
        if ($device === '' || $row->from_peer !== $device) {
            return response()->json(['error' => 'Not your session'], 403);
        }

        $userId = $request->user()->id;
        if ($row->note_user_id !== null && $row->note_user_id !== $userId) {
            return response()->json(['error' => 'Another user already left a note'], 409);
        }

        $row->update(['note' => $note, 'note_user_id' => $userId, 'noted_at' => now()]);

        return response()->json((object) []);
    }

    /**
     * The in-session Note button: POST /api/audit/conn {id, session_id, note},
     * tokenless, sent by the controlling client. `id` is the controlled
     * device. Only remote-control sessions have the button. It may clear the
     * note, and never replaces one written by a signed-in user.
     */
    private function legacyNote(string $id, string $sessionId, mixed $note): void
    {
        if ($id === '' || $sessionId === '' || $sessionId === '0') {
            return;
        }

        $row = AuditConnection::query()
            ->where('rustdesk_id', $id)
            ->where('session_id', $sessionId)
            ->where('conn_type', 0)
            ->latest('id')
            ->first();

        if ($row !== null && $row->note_user_id === null) {
            $clean = $this->cleanNote($note);
            $row->update(['note' => $clean, 'noted_at' => $clean === null ? null : now()]);
        }
    }

    /**
     * Run one audit write at most once per client nonce.
     *
     * Clients from 1.5.0 retry a post unless it gets a 2xx with an empty body,
     * and tag every record with a fresh "nonce" so the server can tell a retry
     * from a new record. A nonce seen in the last five minutes is answered as
     * stored without writing again. A failed write forgets the nonce and
     * answers 500, so the client's retry stores the record.
     *
     * Posts without a nonce (clients before 1.5.0, the in-session Note button)
     * are written every time; those clients ignore the response.
     */
    private function once(Request $request, string $endpoint, string $id, callable $write): Response|JsonResponse
    {
        $nonce = $request->input('nonce');
        $key = is_string($nonce) && trim($nonce) !== ''
            ? 'audit-nonce:'.hash('sha256', $endpoint."\n".$id."\n".$nonce)
            : null;

        if ($key !== null && ! Cache::add($key, true, self::NONCE_TTL_SECONDS)) {
            return $this->stored();
        }

        try {
            $write();
        } catch (Throwable $e) {
            report($e);

            if ($key !== null) {
                try {
                    Cache::forget($key);
                } catch (Throwable $forgetFailed) {
                    report($forgetFailed);
                }
            }

            return response()->json(['error' => 'Audit record not stored'], 500);
        }

        return $this->stored();
    }

    /**
     * The success answer for the tokenless audit posts: 200 with an empty
     * body. 1.5.0 clients treat any non-empty 2xx body, `{}` included, as a
     * failure and post the record again.
     */
    private function stored(): Response
    {
        return response('', 200);
    }

    /**
     * Is this "new" a retry of one already stored? The client posts a
     * connection's audits in order, so a retried "new" always arrives before
     * that connection's "authorized": the row it copies is still open and
     * still "new". Covers retries that carry no usable nonce.
     */
    private function isRetriedNew(string $id, int $connId): bool
    {
        return AuditConnection::query()
            ->where('rustdesk_id', $id)
            ->where('conn_id', $connId)
            ->where('action', 'new')
            ->whereNull('closed_at')
            ->where('created_at', '>=', now()->subSeconds(self::RETRY_WINDOW_SECONDS))
            ->exists();
    }

    /**
     * session_id is a random u64. PHP decodes JSON integers past
     * PHP_INT_MAX as floats and loses digits, so it is read again from the
     * raw body with big integers kept as strings.
     */
    private function sessionId(Request $request): string
    {
        $value = $request->input('session_id', '');

        if ($request->isJson()) {
            $raw = json_decode($request->getContent(), true, 512, JSON_BIGINT_AS_STRING);
            if (is_array($raw) && array_key_exists('session_id', $raw)) {
                $value = $raw['session_id'];
            }
        }

        return is_int($value) || is_string($value) ? (string) $value : '';
    }

    /** The device id the bearer token signed in from, or "". */
    private function tokenDevice(Request $request): string
    {
        $token = $request->attributes->get('client_token');

        return $token instanceof ClientToken ? (string) $token->device_id : '';
    }

    /** Trimmed and capped; null when there is nothing to keep. */
    private function cleanNote(mixed $note): ?string
    {
        if (! is_string($note)) {
            return null;
        }

        $note = trim($note);

        return $note === '' ? null : mb_substr($note, 0, AuditConnection::NOTE_MAX_LENGTH);
    }
}
