<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\ServerLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints CortenDesk Server (hbbs) calls. Authenticated by the shared
 * secret (VerifyServerSecret). Contract: docs/server-link.md.
 */
class ServerLinkController extends Controller
{
    /** Most addresses one report may carry. hbbs batches every few seconds. */
    public const MAX_ADDRS = 500;

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
}
