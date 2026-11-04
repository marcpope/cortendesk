<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\RelaySession;
use App\Services\ConnectionPath;
use App\Services\ServerLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints CortenDesk Server (hbbs and hbbr) calls. Authenticated by the shared
 * secret (VerifyServerSecret). Contract: docs/server-link.md.
 */
class ServerLinkController extends Controller
{
    /** Most addresses one report may carry. hbbs batches every few seconds. */
    public const MAX_ADDRS = 500;

    /** Most relay session reports one request may carry. hbbr sends up to 500. */
    public const MAX_SESSIONS = 500;

    /**
     * GET /api/server/policy: the device policy hbbs enforces. ETag'd, so an
     * unchanged policy costs hbbs a 304.
     */
    public function policy(Request $request, ServerLink $link): Response
    {
        $response = response()->json($link->policy());
        ServerLink::recordPull((string) $request->userAgent());
        $response->setEtag(hash('sha256', (string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }

    /**
     * POST /api/server/local-addrs: LAN addresses hbbs learned during
     * connection setup (issue #76). Unknown and recycled devices are skipped.
     */
    public function localAddrs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'addrs' => ['required', 'array', 'max:'.self::MAX_ADDRS],
            'addrs.*.id' => ['required', 'string', 'max:100'],
            'addrs.*.ip' => ['required', 'ip'],
            'addrs.*.seen_at' => ['nullable', 'integer', 'min:0'],
        ]);

        $now = now();
        $updated = 0;

        foreach ($data['addrs'] as $addr) {
            $seen = isset($addr['seen_at']) ? Carbon::createFromTimestamp($addr['seen_at']) : $now;
            if ($seen->gt($now)) {
                $seen = $now;
            }

            $device = Device::query()->where('rustdesk_id', $addr['id'])->first();
            if ($device === null) {
                continue;
            }

            // Out-of-order batches must not roll the address back.
            if ($device->lan_ip_seen_at !== null && $device->lan_ip_seen_at->gt($seen)) {
                continue;
            }

            $device->forceFill(['lan_ip' => $addr['ip'], 'lan_ip_seen_at' => $seen])->saveQuietly();
            $updated++;
        }

        return response()->json(['updated' => $updated]);
    }

    /**
     * POST /api/server/relay-sessions: sessions hbbr carried (issue #130),
     * once when a pair opens and again with ended_at and bytes when it
     * closes. Upserted on uuid; batches can arrive late or twice.
     */
    public function relaySessions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sessions' => ['required', 'array', 'max:'.self::MAX_SESSIONS],
            'sessions.*.uuid' => ['required', 'string', 'max:64'],
            'sessions.*.id' => ['required', 'string', 'max:100'],
            'sessions.*.conn_type' => ['nullable', 'integer', 'min:0', 'max:255'],
            'sessions.*.controller_ip' => ['nullable', 'ip'],
            'sessions.*.device_ip' => ['nullable', 'ip'],
            'sessions.*.started_at' => ['nullable', 'integer', 'min:0'],
            'sessions.*.ended_at' => ['nullable', 'integer', 'min:0'],
            'sessions.*.bytes' => ['nullable', 'integer', 'min:0'],
        ]);

        foreach ($data['sessions'] as $report) {
            $relay = RelaySession::firstOrNew(['uuid' => $report['uuid']]);
            $relay->fill([
                'rustdesk_id' => $report['id'],
                'conn_type' => ConnectionPath::sessionType((int) ($report['conn_type'] ?? 0)),
                'controller_ip' => $report['controller_ip'] ?? $relay->controller_ip,
                'device_ip' => $report['device_ip'] ?? $relay->device_ip,
                'started_at' => $relay->started_at ?? ConnectionPath::reportedTime($report['started_at'] ?? null) ?? now(),
            ]);
            // A close never gets undone by a late copy of the open.
            if (isset($report['ended_at'])) {
                $relay->ended_at = ConnectionPath::reportedTime($report['ended_at']) ?? now();
                $relay->bytes = max((int) $relay->bytes, (int) ($report['bytes'] ?? 0));
            }
            $relay->save();
            ConnectionPath::linkRelay($relay);
        }

        return response()->json(['stored' => count($data['sessions'])]);
    }
}
