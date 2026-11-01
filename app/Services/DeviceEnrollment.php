<?php

namespace App\Services;

use App\Console\Commands\CheckDeviceNotifications;
use App\Models\ClientToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * What happens to a device the moment it first appears (issues #97 and #95).
 *
 * - A default device group: new devices land in it instead of nowhere, so a
 *   role scoped to that group can see them without an admin filing each one.
 * - Approval by sign-in: with the approval gate on, a device whose RustDesk
 *   client is signed in to this console with a user account is approved on
 *   arrival and that user becomes its owner. Either order works: the device
 *   may register first and the user sign in later, or the reverse.
 */
class DeviceEnrollment
{
    public const SETTING_DEFAULT_GROUP = 'default_device_group_id';

    public const SETTING_AUTO_APPROVE = 'auto_approve_signed_in';

    public const SETTING_NEW_INCOMING_ONLY = 'new_devices_incoming_only';

    /** The configured default group, or null when none is set or it no longer exists. */
    public static function defaultGroup(): ?DeviceGroup
    {
        $id = (int) Setting::get(self::SETTING_DEFAULT_GROUP, '0');

        return $id > 0 ? DeviceGroup::query()->find($id) : null;
    }

    public static function autoApproveSignedIn(): bool
    {
        return Setting::get(self::SETTING_AUTO_APPROVE, '0') === '1';
    }

    /** New devices start incoming only: they can be controlled but cannot start sessions (#107). */
    public static function newDevicesIncomingOnly(): bool
    {
        return Setting::get(self::SETTING_NEW_INCOMING_ONLY, '0') === '1';
    }

    /**
     * Fill in what a first-seen device gets by default. Call before the row
     * is saved; returns the attributes to merge.
     *
     * @return array<string, mixed>
     */
    public static function defaultsForNew(array $attributes): array
    {
        if (empty($attributes['device_group_id']) && ($group = self::defaultGroup()) !== null) {
            $attributes['device_group_id'] = $group->id;
        }
        $attributes['can_initiate'] ??= ! self::newDevicesIncomingOnly();

        return $attributes;
    }

    /**
     * Register the device a client just signed in from (issue #110). Phones
     * never post sysinfo or heartbeats (iOS never, Android only while its
     * incoming service runs), so without this their device has no row, never
     * reaches the server policy's allow list, and approved-only mode refuses
     * every session they start even though their user is signed in.
     *
     * A new row follows the same rules as one created by sysinfo: pending when
     * the approval gate is on, default group, approval by sign-in. A pending
     * row is approved when the setting says so; a recycled one stays recycled.
     *
     * @param  array{id?: ?string, uuid?: ?string, os?: ?string}  $client
     */
    public static function signedIn(User $user, array $client, ?string $ip = null): ?Device
    {
        $id = trim((string) ($client['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        $device = Device::withTrashed()->where('rustdesk_id', $id)->first();
        if ($device?->trashed()) {
            return null;
        }

        if ($device === null) {
            try {
                $device = Device::create(['rustdesk_id' => $id] + self::defaultsForNew([
                    // No hostname: an empty one is what makes the heartbeat
                    // ask for sysinfo, so a client that does send it fills it.
                    'uuid' => (string) ($client['uuid'] ?? ''),
                    'os' => (string) ($client['os'] ?? ''),
                    'status' => Device::approvalGateEnabled() ? Device::STATUS_PENDING : Device::STATUS_ACTIVE,
                    'user_id' => $user->id,
                    'registered_ip' => $ip,
                ]));
            } catch (UniqueConstraintViolationException) {
                // The client's first sysinfo landed in the same instant.
                $device = Device::query()->where('rustdesk_id', $id)->first();
                if ($device === null) {
                    return null;
                }
            }

            if ($device->wasRecentlyCreated) {
                self::approveIfSignedIn($device, $user);

                if ($device->isPending()) {
                    app(AppriseNotifications::class)->sendAfterResponse(
                        'device.pending_approval',
                        'Device pending approval',
                        CheckDeviceNotifications::deviceLabel($device).' is awaiting approval.',
                        'device:'.$device->rustdesk_id,
                        $device,
                    );
                }

                return $device;
            }
        }

        self::approveIfSignedIn($device, $user);

        return $device;
    }

    /**
     * Approve a pending device because its client is signed in. With no
     * $user, the most recent valid client token issued from this device
     * decides. Returns true when the device was approved here.
     */
    public static function approveIfSignedIn(Device $device, ?User $user = null): bool
    {
        if (! $device->isPending() || ! self::autoApproveSignedIn()) {
            return false;
        }

        if ($user === null) {
            $token = ClientToken::query()
                ->where('device_id', $device->rustdesk_id)
                ->whereNotNull('user_id')
                ->latest('id')
                ->first();
            if ($token === null || ! $token->isValid()) {
                return false;
            }
            $user = $token->user;
            if ($user === null) {
                return false;
            }
        }

        if (! $user->is_active) {
            return false;
        }

        $changes = ['status' => Device::STATUS_ACTIVE];
        if ($device->user_id === null) {
            $changes['user_id'] = $user->id;
        }
        $device->update($changes);

        Log::info('Device approved on sign-in', ['device' => $device->rustdesk_id, 'user' => $user->username]);

        return true;
    }
}
