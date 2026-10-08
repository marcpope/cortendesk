<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DevicePresenceNotificationState;
use App\Models\DevicePresenceSnooze;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use App\Services\AppriseNotifications;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Detect device presence transitions for Apprise notifications. */
class CheckDeviceNotifications extends Command
{
    protected $signature = 'cortendesk:check-device-notifications';

    protected $description = 'Detect device offline/recovery transitions for Apprise notifications';

    public function handle(AppriseNotifications $notifications): int
    {
        DevicePresenceSnooze::pruneForSweep();

        // Nothing here holds up a request, so sends get the longer timeout.
        $notifications = $notifications->forScheduler();

        if ($notifications->isEnabledFor('device.offline') || $notifications->isEnabledFor('device.online')) {
            $this->sweep($notifications);
        } else {
            $this->info('Device presence notifications are disabled.');
        }

        $this->retryDeliveries($notifications);

        return self::SUCCESS;
    }

    private function sweep(AppriseNotifications $notifications): void
    {

        // These inputs are deliberately loaded once. The sweep must not turn
        // grace/state/snooze checks into a query per device.
        $graceMinutes = $this->offlineGraceMinutes();
        $snoozedTargets = DevicePresenceSnooze::activeTargets();
        $states = DevicePresenceNotificationState::query()->get()->keyBy('device_id');
        $offline = 0;
        $recovered = 0;

        Device::query()->approved()->orderBy('id')->each(function (Device $device) use ($notifications, $graceMinutes, $snoozedTargets, $states, &$offline, &$recovered): void {
            $state = $states->get($device->id);
            $snoozed = isset($snoozedTargets[DevicePresenceSnooze::TARGET_DEVICE][$device->id])
                || ($device->device_group_id !== null && isset($snoozedTargets[DevicePresenceSnooze::TARGET_GROUP][$device->device_group_id]));

            if ($device->isOnline()) {
                if (self::consumeRecoveryFor($device)) {
                    // consumeRecoveryFor removes the marker before transport,
                    // so concurrent commands cannot duplicate the recovery. A
                    // failed send is retried from its delivery row instead.
                    if (! $snoozed) {
                        $notifications->send(
                            'device.online',
                            'Device recovered',
                            self::deviceLabel($device).' is online again.',
                            'device:'.$device->rustdesk_id,
                            $device,
                        );
                        $recovered++;
                    }
                }

                return;
            }

            if ($state?->offline_notified_at !== null || $snoozed || ! $this->pastOfflineGrace($device, $graceMinutes)) {
                return;
            }

            // The legacy marker belongs to exactly one contender. A sweep
            // winner consumes it before materializing delivered state; an
            // online recovery winner consumes it for its sole recovery.
            $legacyClaim = DevicePresenceNotificationState::claimLegacyMarkerFor($device);
            if ($legacyClaim === null) {
                return;
            }

            if (is_string($legacyClaim)) {
                DevicePresenceNotificationState::convertClaimedLegacyMarkerFor($device, $legacyClaim);

                return;
            }

            // Insert/reclaim a unique durable pending row before calling the
            // external service. Only its owner may complete or release it.
            $claim = DevicePresenceNotificationState::claimOfflineFor($device);
            if ($claim === null) {
                return;
            }

            $delivery = $notifications->send(
                'device.offline',
                'Device offline',
                self::deviceLabel($device).' stopped heartbeating.',
                'device:'.$device->rustdesk_id,
                $device,
            );

            if ($delivery?->status === NotificationDelivery::STATUS_SENT
                && DevicePresenceNotificationState::markDelivered($device, $claim)) {
                $offline++;
            } else {
                DevicePresenceNotificationState::releaseClaim($device, $claim);
            }
        });

        $this->info("Detected {$offline} offline and {$recovered} recovered device transition(s).");
    }

    /**
     * Retry failed deliveries from any path (issue #87). An offline alert
     * that gets through on a retry records the outage, the same as a first
     * send would, so its recovery follows and the sweep does not send the
     * offline alert again.
     */
    private function retryDeliveries(AppriseNotifications $notifications): void
    {
        $resent = $notifications->retryDue();
        if ($resent->isEmpty()) {
            return;
        }

        foreach ($resent as $delivery) {
            if ($delivery->event !== 'device.offline'
                || $delivery->status !== NotificationDelivery::STATUS_SENT
                || ! str_starts_with((string) $delivery->subject, 'device:')) {
                continue;
            }

            $device = Device::query()->where('rustdesk_id', substr((string) $delivery->subject, 7))->first();
            if ($device !== null && ! $device->isOnline()) {
                DevicePresenceNotificationState::recordDeliveredOffline($device);
            }
        }

        $sent = $resent->where('status', NotificationDelivery::STATUS_SENT)->count();
        $this->info("Retried {$resent->count()} notification(s), {$sent} sent.");
    }

    /** Notify recovery on the first heartbeat after a confirmed offline delivery. */
    public static function reportOnline(Device $device, AppriseNotifications $notifications): void
    {
        if ($device->status !== Device::STATUS_ACTIVE || ! self::consumeRecoveryFor($device)) {
            return;
        }

        // A snooze closes the outage without emitting either side. Recovery is
        // at-most-once because the marker was atomically consumed above.
        if (! DevicePresenceSnooze::isActiveFor($device)) {
            $notifications->sendAfterResponse(
                'device.online',
                'Device recovered',
                self::deviceLabel($device).' is online again.',
                'device:'.$device->rustdesk_id,
                $device,
            );
        }
    }

    public static function deviceLabel(Device $device): string
    {
        $name = trim((string) ($device->alias ?: $device->hostname));

        return $name === '' ? 'Device '.$device->rustdesk_id : $name.' ('.$device->rustdesk_id.')';
    }

    /** Consume a durable marker or the shared legacy claim exactly once. */
    private static function consumeRecoveryFor(Device $device): bool
    {
        // Fast path: no legacy marker and no durable state means nothing to
        // recover, which is every heartbeat of a device that never went
        // offline. Two cheap reads instead of lock traffic; a marker created
        // between this check and the next tick is picked up then.
        if (! DevicePresenceNotificationState::hasAnyRecoverableStateFor($device)) {
            return false;
        }

        $legacyClaim = DevicePresenceNotificationState::claimLegacyMarkerFor($device);

        // A claimant may be converting the marker into durable state. Let that
        // winner finish rather than deleting state it is about to materialize.
        if ($legacyClaim === null) {
            return false;
        }

        if ($legacyClaim === false) {
            return DevicePresenceNotificationState::consumeFor($device);
        }

        // Keep the legacy lock through both deletions. This also cleans up old
        // upgrades that had durable state plus the original cache marker.
        $durableRecovery = DevicePresenceNotificationState::consumeFor($device);
        $legacyRecovery = DevicePresenceNotificationState::consumeClaimedLegacyMarkerFor($device, $legacyClaim);

        return $durableRecovery || $legacyRecovery;
    }

    private function pastOfflineGrace(Device $device, int $graceMinutes): bool
    {
        $offlineSince = $device->last_online_at?->copy()->addSeconds(Device::onlineWindow())
            ?? $device->created_at;

        return $offlineSince instanceof Carbon
            && $offlineSince->addMinutes($graceMinutes)->lte(now());
    }

    private function offlineGraceMinutes(): int
    {
        return max(0, min(1440, (int) Setting::get('apprise_offline_grace_minutes', '0')));
    }
}
