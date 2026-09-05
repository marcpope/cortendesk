<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\CheckDeviceNotifications;
use App\Http\Controllers\Controller;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\Strategy;
use App\Services\AppriseNotifications;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SyncController extends Controller
{
    /**
     * POST /api/heartbeat — spec §8. Tokenless, fire-and-forget.
     *
     * Response keys (all optional): `sysinfo` (presence forces re-upload),
     * `disconnect` (conn ids to close), `modified_at` + `strategy` (PLAN C3).
     */
    public function heartbeat(Request $request)
    {
        $id = (string) $request->input('id', '');
        $uuid = (string) $request->input('uuid', '');

        if ($id === '') {
            return response()->json((object) []);
        }

        $device = Device::withTrashed()->where('rustdesk_id', $id)->first();

        // Unknown device: ask it to introduce itself via /api/sysinfo.
        if ($device === null) {
            return response()->json(['sysinfo' => 1]);
        }

        // Recycled devices stay invisible: swallow the heartbeat without
        // requesting sysinfo, so the client idles quietly.
        if ($device->trashed()) {
            return response()->json((object) []);
        }

        // uuid pinning: once a device has a uuid, a mismatching sender may not
        // update presence (spoof guard for this tokenless endpoint).
        if (! $this->uuidMatches($device->uuid, $uuid)) {
            return response()->json((object) []);
        }

        $update = [
            'last_online_at' => now(),
            'last_online_ip' => $request->ip(),
        ];

        // Self-heal legacy imports: pin the uuid in the exact form the
        // client sends (base64) so future comparisons are direct.
        if ($uuid !== '' && $device->uuid !== $uuid) {
            $update['uuid'] = $uuid;
        }

        $device->forceFill($update)->saveQuietly();
        CheckDeviceNotifications::reportOnline($device, app(AppriseNotifications::class));

        $response = [];

        // Device row exists but has never sent inventory — request it.
        if ($device->hostname === null || $device->hostname === '') {
            $response['sysinfo'] = 1;
        }

        // Reconcile Active sessions against what the device says is actually
        // live (issue #10). Until now a session was only ever closed by the
        // client posting action=close on the audit endpoint, so any end that
        // skipped that post — reboot, crash, network drop, service stopped —
        // left the row open forever and the console showed it as Active.
        //
        // `conns` is the device's own list of live incoming connections and is
        // authoritative. It is OMITTED ENTIRELY when empty (docs/client-api.md
        // §8), so the absent case is the important one: it means "nothing is
        // live here", which is exactly what a rebooted machine reports.
        $live = array_map('intval', (array) $request->input('conns', []));
        AuditConnection::reconcileFor($id, $live);

        // Operator-requested session termination (docs/client-api.md §8). The
        // client closes each listed connection; this is the only channel the
        // console has for reaching a live session, which runs peer-to-peer and
        // never touches this API otherwise.
        $disconnect = AuditConnection::pendingDisconnectsFor($id);
        if ($disconnect !== []) {
            $response['disconnect'] = $disconnect;
        }

        // Strategy push (PLAN C3, wire contract docs/strategy-protocol.md).
        // `modified_at` is the version token the device echoed back to us; it is
        // an opaque i64 the client never interprets, so change detection is
        // entirely ours. deliveryFor() returns null whenever there is nothing to
        // say — which is what keeps this response byte-identical to the
        // pre-strategy one for every device that has no policy.
        $delivery = Strategy::deliveryFor($device, (int) $request->input('modified_at', 0));
        if ($delivery !== null) {
            $response['modified_at'] = $delivery['modified_at'];
            $response['strategy'] = $delivery['strategy'];
        }

        return response()->json((object) $response);
    }

    /**
     * POST /api/sysinfo — spec §9. Tokenless. Plain-text response strings
     * are exact: SYSINFO_UPDATED | ID_NOT_FOUND.
     */
    public function sysinfo(Request $request): Response
    {
        $id = (string) $request->input('id', '');
        $uuid = (string) $request->input('uuid', '');

        if ($id === '') {
            return response('ID_NOT_FOUND');
        }

        $device = Device::withTrashed()->where('rustdesk_id', $id)->first();

        // Recycled: acknowledge so the client stops retrying, change nothing.
        if ($device?->trashed()) {
            return response('SYSINFO_UPDATED');
        }

        if ($device !== null && ! $this->uuidMatches($device->uuid, $uuid)) {
            // Same RustDesk id from a different machine — reject silently.
            return response('SYSINFO_UPDATED');
        }

        $attributes = [
            'uuid' => $uuid,
            'cpu' => (string) $request->input('cpu', ''),
            'memory' => (string) $request->input('memory', ''),
            'os' => (string) $request->input('os', ''),
            'hostname' => (string) $request->input('hostname', ''),
            'username' => (string) $request->input('username', ''),
            'version' => (string) $request->input('version', ''),
            'last_online_at' => now(),
            'last_online_ip' => $request->ip(),
        ];

        if ($device === null) {
            // Deployment approval gate (PLAN B3): when enabled, a first-seen
            // id+uuid is quarantined as 'pending' — registered but excluded from
            // scoping/address books/group tab/device list until an operator
            // approves it on the Devices → Pending tab. The plain-text ack stays
            // identical so the client sees no difference.
            $attributes['status'] = Device::approvalGateEnabled()
                ? Device::STATUS_PENDING
                : Device::STATUS_ACTIVE;

            // Where the device FIRST appeared from — set here and never on
            // update, so later heartbeats cannot rewrite history (issue #46).
            $attributes['registered_ip'] = $request->ip();

            $device = Device::updateWithStrategyContext(
                new Device(['rustdesk_id' => $id]),
                $attributes,
            );

            if ($device->isPending()) {
                app(AppriseNotifications::class)->sendAfterResponse(
                    'device.pending_approval',
                    'Device pending approval',
                    CheckDeviceNotifications::deviceLabel($device).' is awaiting approval.',
                    'device:'.$device->rustdesk_id,
                    $device,
                );
            }
        } else {
            $device->update($attributes);
            CheckDeviceNotifications::reportOnline($device, app(AppriseNotifications::class));
        }

        return response('SYSINFO_UPDATED');
    }

    /**
     * Compare a stored device uuid with the one a client sent.
     *
     * Clients send base64(machine uuid) (spec §0.6), but databases imported
     * from lejianwen/rustdesk-api hold a mix of base64 and plain uuids (its
     * peers table stored both forms for the same machine). Treat values as
     * equal when they match after base64 canonicalization in either
     * direction; genuinely different machines still mismatch.
     */
    private function uuidMatches(string $stored, string $sent): bool
    {
        if ($stored === '' || $sent === '') {
            return true;
        }

        if (hash_equals($stored, $sent)) {
            return true;
        }

        $decodedSent = base64_decode($sent, true);
        if ($decodedSent !== false && hash_equals($stored, rtrim($decodedSent, "\0"))) {
            return true;
        }

        $decodedStored = base64_decode($stored, true);

        return $decodedStored !== false && hash_equals(rtrim($decodedStored, "\0"), $sent);
    }
}
