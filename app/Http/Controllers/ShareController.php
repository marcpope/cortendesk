<?php

namespace App\Http\Controllers;

use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceShare;
use App\Models\DeviceShareSession;
use App\Models\Setting;
use App\Services\ServerLink;
use App\Support\IceServers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The page a share link opens, for people without a console account
 * (docs/share-links.md).
 *
 * Links look like /s#<token>. The fragment never reaches the server, so the
 * token is in no access log: the page posts it to start(). The viewer then
 * connects with the ordinary web client and no password, so the device
 * opens RustDesk's accept window and the person there decides. The console
 * only checks the link, its expiry and its viewer limit, and keeps the log.
 */
class ShareController extends Controller
{
    public function show(): Response
    {
        abort_unless(config('cortendesk.share_links'), 404);

        $messages = array_replace(
            (array) trans('webclient', [], 'en'),
            (array) trans('webclient'),
        );

        return response()
            ->view('share', ['messages' => $messages])
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store')
            ->header('X-Frame-Options', 'DENY');
    }

    /** Claim a viewer slot and hand out what the web client needs to connect. */
    public function start(Request $request): JsonResponse
    {
        abort_unless(config('cortendesk.share_links'), 404);
        $share = DeviceShare::findUsableByToken((string) $request->input('token', ''));
        if (! $share) {
            return $this->refuse(404, 'link_invalid');
        }

        $key = Str::random(48);
        $session = new DeviceShareSession([
            'key_hash' => hash('sha256', $key),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);
        if (! $this->claimSlot($share, $session)) {
            return $this->refuse(409, 'link_full');
        }
        $share->forceFill(['last_used_at' => now()])->save();

        /** @var Device $device */
        $device = $share->device;
        ConsoleAudit::system('device.share.open', 'Share link "'.$share->label.'" opened for device '.$device->rustdesk_id.' from '.$request->ip(), 'device', $device->rustdesk_id);

        [$wsIdUrl, $wsRelayUrl] = WebClientPageController::wsUrls($request);

        return response()->json([
            'session' => $key,
            'label' => $share->label,
            'device_name' => (string) ($device->alias ?: $device->hostname ?: $device->rustdesk_id),
            'expires_at' => $share->expires_at->getTimestamp(),
            'ping_seconds' => DeviceShareSession::PING_SECONDS,
            'client' => [
                'peerId' => $device->rustdesk_id,
                'serverKeyB64' => Setting::server('public_key'),
                'wsIdUrl' => $wsIdUrl,
                'wsRelayUrl' => $wsRelayUrl,
                'myId' => 'share-'.$share->id,
                // Shown in the device's accept window.
                'myName' => Str::limit($share->label, 60, '').' (share link)',
                'signalToken' => ServerLink::shareTicket($share),
                'webrtc' => IceServers::forShareViewer(),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * The share page is still open. Answers with a fresh hbbs ticket, which
     * lives only minutes (ServerLink::shareTicket). 410 tells the page to
     * disconnect.
     */
    public function ping(Request $request): JsonResponse
    {
        abort_unless(config('cortendesk.share_links'), 404);
        $session = $this->session($request);
        if (! $session) {
            return $this->refuse(410, 'link_ended');
        }
        $share = $session->share;
        if (! $share->isUsable()) {
            $session->forceFill(['ended_at' => now()])->save();

            return $this->refuse(410, 'link_ended');
        }
        // Lapsed (no ping for LIVE_SECONDS): its slot may have gone to someone
        // else, so it comes back only if there is still room.
        if ($session->last_seen_at->lte(now()->subSeconds(DeviceShareSession::LIVE_SECONDS))) {
            $session->last_seen_at = now();
            if (! $this->claimSlot($share, $session)) {
                $session->forceFill(['ended_at' => now()])->save();

                return $this->refuse(410, 'link_full');
            }
        } else {
            $session->forceFill(['last_seen_at' => now()])->save();
        }

        return response()
            ->json(['ok' => true, 'signal_token' => ServerLink::shareTicket($share)])
            ->header('Cache-Control', 'no-store');
    }

    /** The share page was closed. */
    public function end(Request $request): JsonResponse
    {
        abort_unless(config('cortendesk.share_links'), 404);
        $this->session($request)?->forceFill(['ended_at' => now()])->save();

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    /**
     * Save $session as live if the share has room for it.
     *
     * Write first, then count, under a lock on the share row: two viewers
     * claiming the last slot at once cannot both see room. On SQLite, where
     * lockForUpdate does nothing, the write itself takes the database lock,
     * so the second count still sees the first row.
     */
    private function claimSlot(DeviceShare $share, DeviceShareSession $session): bool
    {
        try {
            DB::transaction(function () use ($share, $session) {
                DeviceShare::query()->whereKey($share->id)->lockForUpdate()->first();
                $share->sessions()->save($session);
                if ($share->sessions()->live()->count() > $share->max_viewers) {
                    throw new \OverflowException('share link full');
                }
            });
        } catch (\OverflowException) {
            return false;
        }

        return true;
    }

    /** The open session for this token and session key, or null. */
    private function session(Request $request): ?DeviceShareSession
    {
        $token = (string) $request->input('token', '');
        $key = (string) $request->input('session', '');
        if (! DeviceShare::validTokenFormat($token) || $key === '') {
            return null;
        }

        return DeviceShareSession::query()
            ->with('share.device', 'share.user')
            ->where('key_hash', hash('sha256', $key))
            ->whereNull('ended_at')
            ->whereHas('share', fn ($q) => $q->where('token_hash', DeviceShare::hashToken($token)))
            ->first();
    }

    private function refuse(int $status, string $reason): JsonResponse
    {
        return response()
            ->json(['reason' => $reason, 'error' => match ($reason) {
                'link_full' => __('shares.link_full'),
                'link_ended' => __('shares.link_ended'),
                default => __('shares.link_invalid'),
            }], $status)
            ->header('Cache-Control', 'no-store');
    }
}
