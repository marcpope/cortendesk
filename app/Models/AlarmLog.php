<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['rustdesk_id', 'uuid', 'typ', 'info', 'conn_id'])]
class AlarmLog extends Model
{
    /**
     * Alarm types posted by the client to POST /api/audit/alarm.
     * Enumerated in docs/client-api.md §21 (derived from the client's audit
     * code): label + bootstrap severity used for the console badge.
     *
     * @var array<int, array{label: string, severity: string}>
     */
    public const TYPES = [
        0 => ['label' => 'IP whitelist block', 'severity' => 'danger'],
        1 => ['label' => 'Many failed attempts (>30)', 'severity' => 'danger'],
        2 => ['label' => 'Rapid access attempts', 'severity' => 'warning'],
        6 => ['label' => 'IPv6 prefix attempts exceeded', 'severity' => 'warning'],
        7 => ['label' => 'Terminal login backoff', 'severity' => 'warning'],
        8 => ['label' => 'Terminal login concurrency', 'severity' => 'warning'],
        9 => ['label' => 'Session scope violation', 'severity' => 'danger'],
        10 => ['label' => 'ID whitelist block', 'severity' => 'danger'],

        // Console-raised alarms. Deliberately numbered from 100 so they can
        // never collide with a client type the upstream protocol adds later:
        // the client's types start at 0 and the range below 100 is left to it.
        100 => ['label' => 'Console brute force', 'severity' => 'danger'],
        101 => ['label' => 'Console password spraying', 'severity' => 'danger'],
    ];

    /** Repeated failed sign-ins against one console account. */
    public const TYP_BRUTE_FORCE = 100;

    /** Many failed sign-ins from one address, spread across accounts. */
    public const TYP_SPRAYING = 101;

    /**
     * Placeholder device id for alarms the console raises itself.
     *
     * `alarm_logs.rustdesk_id` is not nullable and normally holds the device
     * that reported the alarm. Real device ids are numeric, so this sentinel
     * can never collide — and because the alarm list scopes non-admins to
     * their own visible devices, it keeps console security alarms admin-only.
     */
    public const CONSOLE_SOURCE = 'console';

    /**
     * Record a console-raised alarm.
     *
     * @param  array<string, mixed>  $info
     */
    public static function console(int $typ, array $info = []): self
    {
        return static::create([
            'rustdesk_id' => self::CONSOLE_SOURCE,
            'typ' => $typ,
            // Same convention as the client: `info` is a JSON-encoded string.
            'info' => json_encode($info),
        ]);
    }

    protected function casts(): array
    {
        return [
            'typ' => 'integer',
        ];
    }

    /** Human label for this alarm's typ; "Type N" when unknown. */
    public function typeLabel(): string
    {
        return self::TYPES[$this->typ]['label'] ?? 'Type '.$this->typ;
    }

    /** Bootstrap severity suffix (danger|warning|info|secondary) for the badge. */
    public function typeSeverity(): string
    {
        return self::TYPES[$this->typ]['severity'] ?? 'secondary';
    }

    /**
     * The client sends `info` as a JSON-encoded string (spec §21). Decode it
     * into scalar key/value pairs for display; empty when not a JSON object.
     *
     * @return array<string, string>
     */
    public function infoPairs(): array
    {
        $decoded = json_decode((string) $this->info, true);
        if (! is_array($decoded)) {
            return [];
        }

        $pairs = [];
        foreach ($decoded as $key => $value) {
            $pairs[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        return $pairs;
    }
}
