<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DevicePresenceSnooze;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Small synchronous Apprise API client. It uses Laravel's HTTP client rather
 * than spawning a binary, keeping URLs and credentials out of process args.
 */
class AppriseNotifications
{
    public const MODE_CONFIG = 'config';

    public const MODE_URLS = 'urls';

    public const EVENTS = [
        'device.pending_approval' => 'Device pending approval',
        'device.offline' => 'Device offline',
        'device.online' => 'Device recovered',
        'console.login_failed' => 'Failed console login',
        'security.alarm' => 'Security alarm',
        'remote_connection.failure' => 'Repeated remote connection failure',
    ];

    /**
     * Apprise message type per event. Slack, Discord and the like colour the
     * message by it (issue #53). Apprise has four: info, success, warning,
     * failure. Anything unmapped, such as the settings test, is info.
     */
    public const TYPES = [
        'device.pending_approval' => 'warning',
        'device.offline' => 'info',
        'device.online' => 'success',
        'console.login_failed' => 'warning',
        'security.alarm' => 'failure',
        'remote_connection.failure' => 'failure',
    ];

    /** Offline and recovery share a subject; a newer one makes an older one stale. */
    public const PRESENCE_EVENTS = ['device.offline', 'device.online'];

    /** HTTP timeout on request paths, where a slow Apprise must not hold the worker. */
    public const REQUEST_TIMEOUT_SECONDS = 3;

    /** Sends in total, the first one included. */
    public const MAX_ATTEMPTS = 3;

    /** Wait before the second and the third send, in seconds. */
    public const RETRY_DELAYS = [60, 300];

    /** A failure older than this is not retried: the news is stale. */
    public const RETRY_WINDOW_MINUTES = 30;

    private bool $scheduled = false;

    private bool $scheduledStalled = false;

    /**
     * Send an enabled event. The subject scopes its cooldown (a silent device
     * must not suppress a different device's security event).
     */
    public function send(string $event, string $title, string $body, ?string $subject = null, ?Device $device = null): ?NotificationDelivery
    {
        if (! $this->isEnabledFor($event) || ! $this->allowsDevice($event, $device)) {
            return null;
        }

        $cooldown = $this->cooldownSeconds();
        $key = 'apprise:cooldown:'.sha1($event.'|'.($subject ?? 'global'));

        if ($cooldown > 0 && ! Cache::add($key, true, $cooldown)) {
            // A minute-by-minute presence sweep would otherwise create a log
            // row for every suppressed tick. Delivery history records actual
            // attempts; the cache enforces quiet periods without audit noise.
            return null;
        }

        return $this->deliver($event, $title, $body, $subject);
    }

    /**
     * Queue a send to run once the response has been flushed to the client.
     *
     * Delivery is a synchronous HTTP call with a four-second worst case, so
     * anything on a request path uses this instead of send(). It matters most
     * where the caller is unauthenticated and repeatable: a failed sign-in that
     * blocks its own response lets someone spray usernames and hold a php-fpm
     * worker for four seconds a time.
     *
     * afterResponse() works on the sync queue because php-fpm flushes and
     * closes the connection first, so the wait lands on the worker rather than
     * on the person in front of the browser.
     */
    public function sendAfterResponse(string $event, string $title, string $body, ?string $subject = null, ?Device $device = null): void
    {
        // Cheap checks now, so a disabled or unconfigured install does not
        // register a terminating callback on every request that could send.
        if (! $this->isEnabledFor($event) || ! $this->allowsDevice($event, $device)) {
            return;
        }

        dispatch(function () use ($event, $title, $body, $subject, $device): void {
            $this->send($event, $title, $body, $subject, $device);
        })->afterResponse();
    }

    /**
     * A copy for the scheduler, with the longer configured HTTP timeout.
     * Apprise answers only after it has posted to the destination, so a slow
     * Slack or SMTP hop can take longer than a request path can wait.
     */
    public function forScheduler(): static
    {
        $copy = clone $this;
        $copy->scheduled = true;
        $copy->scheduledStalled = false;

        return $copy;
    }

    /**
     * Re-send failed deliveries whose retry is due (issue #87).
     *
     * Runs inside the per-minute scheduler, so it is bounded: at most $limit
     * rows, no new send after $budgetSeconds, and it stops at the first
     * connection failure, since the rest would wait on the same endpoint.
     * Rows it skips stay due for the next run.
     *
     * @return Collection<int, NotificationDelivery> the rows it sent again
     */
    public function retryDue(int $limit = 10, int $budgetSeconds = 45): Collection
    {
        $started = hrtime(true);
        $resent = new Collection;

        NotificationDelivery::query()
            ->whereNotNull('next_retry_at')
            ->where('created_at', '<', now()->subMinutes(self::RETRY_WINDOW_MINUTES))
            ->update(['next_retry_at' => null, 'body' => null]);

        $due = NotificationDelivery::query()
            ->where('status', NotificationDelivery::STATUS_FAILED)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($due as $delivery) {
            if ((hrtime(true) - $started) / 1e9 >= $budgetSeconds) {
                break;
            }

            // Claim by attempt count, so two overlapping runs cannot both send.
            $claimed = NotificationDelivery::query()
                ->whereKey($delivery->id)
                ->where('attempts', $delivery->attempts)
                ->whereNotNull('next_retry_at')
                ->update(['next_retry_at' => null]) === 1;

            if (! $claimed) {
                continue;
            }

            if ($this->isSuperseded($delivery)) {
                $delivery->forceFill(['status' => NotificationDelivery::STATUS_SUPERSEDED, 'next_retry_at' => null, 'body' => null])->save();

                continue;
            }

            if (! $this->isEnabledFor($delivery->event) || $delivery->body === null || $this->isSnoozed($delivery)) {
                $delivery->forceFill(['next_retry_at' => null, 'body' => null])->save();

                continue;
            }

            $outcome = $this->post($delivery->event, $delivery->title, $delivery->body);
            $attempts = $delivery->attempts + 1;
            $retryAt = $outcome['retryable'] ? $this->nextRetryAt($attempts) : null;

            $delivery->forceFill([
                'status' => $outcome['status'],
                'attempts' => $attempts,
                'next_retry_at' => $retryAt,
                'body' => $retryAt === null ? null : $delivery->body,
                'error' => $outcome['error'] === null ? null : mb_substr($outcome['error'], 0, 2_000),
            ])->save();

            $resent->push($delivery);

            if ($outcome['connection']) {
                break;
            }
        }

        return $resent;
    }

    /** Send a saved-config test regardless of the event switches. */
    public function test(): NotificationDelivery
    {
        return $this->deliver(
            'test',
            'CortenDesk notification test',
            'This is a test notification from '.config('app.name').'.',
            'settings-test',
        );
    }

    public function isConfigured(): bool
    {
        $endpoint = $this->secret('apprise_endpoint');
        if (! $this->validEndpoint($endpoint)) {
            return false;
        }

        return $this->mode() === self::MODE_CONFIG
            ? trim($this->secret('apprise_config_key')) !== ''
            : $this->urls() !== [];
    }

    public function isEnabledFor(string $event): bool
    {
        return Setting::get('apprise_enabled', '0') === '1'
            && array_key_exists($event, self::EVENTS)
            && Setting::get('apprise_event_'.$this->settingSuffix($event), '0') === '1'
            && $this->isConfigured();
    }

    /**
     * Redact transport credentials from delivery failures and UI/audit strings.
     * The method is public so callers adding user-visible diagnostics can use
     * the same single safe transformation.
     */
    public static function redact(string $value): string
    {
        // Keep transport details out of persistent logs. Apprise destinations use
        // many non-HTTP schemes and commonly place tokens in their path, so the
        // whole URI is removed rather than trying to preserve a "safe" prefix.
        $value = preg_replace(
            '~\b[a-z][a-z0-9+.-]*://[^\s"\']+~i',
            '[redacted URL]',
            $value,
        ) ?? '[redacted error]';

        // Error responses are often JSON. Redact quoted sensitive keys before
        // the generic key=value pass so echoed credentials never reach the
        // delivery table, logs, or Settings UI.
        $value = preg_replace(
            '/("(?:token|secret|password|authorization|api[_-]?key|access[_-]?token)"\s*:\s*)"(?:\\\\.|[^"\\\\])*"/i',
            '$1"[redacted]"',
            $value,
        ) ?? '[redacted error]';

        return preg_replace(
            '/\b(token|secret|password|authorization|api[_-]?key|access[_-]?token)\s*[=:]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $value,
        ) ?? '[redacted error]';
    }

    private function deliver(string $event, string $title, string $body, ?string $subject): NotificationDelivery
    {
        if (! $this->isConfigured()) {
            return $this->record($event, $title, $subject, NotificationDelivery::STATUS_FAILED, 'Apprise is not configured.');
        }

        $outcome = $this->post($event, $title, $body);

        // The settings test reports straight back to the person who clicked
        // it, so only real events are queued for a retry.
        $retry = $outcome['retryable'] && array_key_exists($event, self::EVENTS);

        return $this->record($event, $title, $subject, $outcome['status'], $outcome['error'], $retry ? $body : null);
    }

    /**
     * One best-effort POST. Failures are contained and never change API or
     * authentication responses. Timeouts, connection errors, HTTP 429, 424
     * and 5xx are worth retrying; other 4xx mean the request itself is
     * wrong. The Apprise API answers 424 when it reached Apprise but a
     * destination such as Slack failed, which is the transient case (#87).
     *
     * @return array{status: string, error: ?string, retryable: bool, connection: bool}
     */
    private function post(string $event, string $title, string $body): array
    {
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout($this->timeoutSeconds())
                ->connectTimeout($this->scheduled ? min(3, $this->timeoutSeconds()) : 1)
                ->post($this->notifyUrl(), $this->payload($event, $title, $body));

            if ($response->successful()) {
                return ['status' => NotificationDelivery::STATUS_SENT, 'error' => null, 'retryable' => false, 'connection' => false];
            }

            $status = $response->status();

            return [
                'status' => NotificationDelivery::STATUS_FAILED,
                'error' => self::redact('Apprise returned HTTP '.$status.'. '.(string) $response->body()),
                'retryable' => $status === 429 || $status === 424 || $status >= 500,
                'connection' => false,
            ];
        } catch (ConnectionException $e) {
            Log::warning('Apprise notification delivery failed.', ['event' => $event, 'error' => self::redact($e->getMessage())]);

            // One stalled endpoint per run is enough: later scheduled sends in
            // the same run fall back to the short timeout, so a sweep that
            // finds many devices offline cannot outlast its overlap lock.
            $this->scheduledStalled = $this->scheduled;

            return ['status' => NotificationDelivery::STATUS_FAILED, 'error' => self::redact($e->getMessage()), 'retryable' => true, 'connection' => true];
        } catch (Throwable $e) {
            // Notifications must never break auth, presence, or audit APIs.
            Log::warning('Apprise notification delivery failed.', ['event' => $event, 'error' => self::redact($e->getMessage())]);

            return ['status' => NotificationDelivery::STATUS_FAILED, 'error' => self::redact($e->getMessage()), 'retryable' => false, 'connection' => false];
        }
    }

    private function timeoutSeconds(): int
    {
        if (! $this->scheduled || $this->scheduledStalled) {
            return self::REQUEST_TIMEOUT_SECONDS;
        }

        return max(1, min(60, (int) config('cortendesk.notifications.scheduled_timeout', 15)));
    }

    /** When to send again after $attemptsMade sends, or null when done. */
    private function nextRetryAt(int $attemptsMade): ?Carbon
    {
        if ($attemptsMade >= self::MAX_ATTEMPTS) {
            return null;
        }

        // Rounded down to the minute so the per-minute scheduler picks it up
        // on the run the delay points at rather than one run later.
        return now()->addSeconds(self::RETRY_DELAYS[$attemptsMade - 1])->startOfMinute();
    }

    /**
     * A retry is stale once a newer delivery exists for the same subject and
     * event (offline and recovery count as one), or once the device's
     * presence no longer matches what the message says.
     */
    private function isSuperseded(NotificationDelivery $delivery): bool
    {
        $presence = in_array($delivery->event, self::PRESENCE_EVENTS, true);

        if ($delivery->subject !== null && NotificationDelivery::query()
            ->where('subject', $delivery->subject)
            ->whereIn('event', $presence ? self::PRESENCE_EVENTS : [$delivery->event])
            ->where('id', '>', $delivery->id)
            ->exists()) {
            return true;
        }

        if (! $presence) {
            return false;
        }

        $device = $this->subjectDevice($delivery);

        return $device === null
            || ($delivery->event === 'device.offline' ? $device->isOnline() : ! $device->isOnline());
    }

    private function isSnoozed(NotificationDelivery $delivery): bool
    {
        if (! in_array($delivery->event, self::PRESENCE_EVENTS, true)) {
            return false;
        }

        $device = $this->subjectDevice($delivery);

        return $device !== null && DevicePresenceSnooze::isActiveFor($device);
    }

    private function subjectDevice(NotificationDelivery $delivery): ?Device
    {
        $subject = (string) $delivery->subject;

        return str_starts_with($subject, 'device:')
            ? Device::query()->where('rustdesk_id', substr($subject, 7))->first()
            : null;
    }

    /** @return array<string, mixed> */
    private function payload(string $event, string $title, string $body): array
    {
        $payload = [
            'title' => $title,
            'body' => $body,
            'type' => self::TYPES[$event] ?? 'info',
        ];

        if ($this->mode() === self::MODE_URLS) {
            $payload['urls'] = $this->urls();
        }

        return $payload;
    }

    private function notifyUrl(): string
    {
        $endpoint = $this->secret('apprise_endpoint');
        $parts = parse_url($endpoint);

        // isConfigured() verified this already. Preserve an endpoint query
        // (some self-hosted Apprise APIs protect their API route that way) but
        // put it AFTER the /notify path, never inside the config key path.
        $base = ($parts['scheme'] ?? 'https').'://';
        if (isset($parts['user']) || isset($parts['pass'])) {
            $base .= rawurlencode((string) ($parts['user'] ?? ''));
            if (isset($parts['pass'])) {
                $base .= ':'.rawurlencode((string) $parts['pass']);
            }
            $base .= '@';
        }
        $base .= $parts['host'] ?? '';
        if (isset($parts['port'])) {
            $base .= ':'.$parts['port'];
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/').'/notify';
        if ($this->mode() === self::MODE_CONFIG) {
            $path .= '/'.rawurlencode($this->secret('apprise_config_key'));
        }

        return $base.$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function mode(): string
    {
        return Setting::get('apprise_delivery_mode', self::MODE_CONFIG) === self::MODE_URLS
            ? self::MODE_URLS
            : self::MODE_CONFIG;
    }

    /** @return list<string> */
    private function urls(): array
    {
        $decoded = json_decode($this->secret('apprise_urls'), true);

        return is_array($decoded)
            ? array_values(array_filter(array_map('trim', $decoded), fn ($url) => is_string($url) && $url !== ''))
            : [];
    }

    private function secret(string $key): string
    {
        $value = Setting::get($key, '') ?? '';
        if ($value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            // Existing plaintext values are ignored rather than accidentally
            // copied into a URL/error path. Operators can re-save securely.
            return '';
        }
    }

    private function cooldownSeconds(): int
    {
        return max(0, min(1440, (int) Setting::get('apprise_cooldown_minutes', '15'))) * 60;
    }

    private function settingSuffix(string $event): string
    {
        return str_replace(['.', '-'], '_', $event);
    }

    private function allowsDevice(string $event, ?Device $device): bool
    {
        $suffix = $this->settingSuffix($event);
        if ($device === null || Setting::get('apprise_scope_'.$suffix, 'all') !== 'selected') {
            return true;
        }

        $groupIds = json_decode((string) Setting::get('apprise_scope_groups_'.$suffix, '[]'), true);
        $deviceIds = json_decode((string) Setting::get('apprise_scope_devices_'.$suffix, '[]'), true);
        $groups = array_map('intval', is_array($groupIds) ? $groupIds : []);
        $devices = array_map('intval', is_array($deviceIds) ? $deviceIds : []);

        return in_array((int) $device->id, $devices, true)
            || ($device->device_group_id !== null && in_array((int) $device->device_group_id, $groups, true));
    }

    private function validEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        return is_array($parts)
            && isset($parts['host'])
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true);
    }

    private function record(string $event, string $title, ?string $subject, string $status, ?string $error = null, ?string $retryBody = null): NotificationDelivery
    {
        $retryAt = $retryBody === null ? null : $this->nextRetryAt(1);

        return NotificationDelivery::create([
            'event' => $event,
            'subject' => $subject,
            'status' => $status,
            'attempts' => 1,
            'next_retry_at' => $retryAt,
            'title' => self::redact($title),
            'body' => $retryAt === null ? null : $retryBody,
            'error' => $error === null ? null : mb_substr(self::redact($error), 0, 2_000),
        ]);
    }
}
