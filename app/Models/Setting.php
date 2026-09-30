<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->find($key)?->value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        // Device memoizes the online window per process; without this flush a
        // long-lived process (schedule:work, queue workers, the test runner)
        // would keep judging presence by the OLD value after a settings save.
        if ($key === 'online_window') {
            Device::flushOnlineWindowCache();
        }
    }

    /**
     * A server value (id_server, relay_server, public_key): the saved setting,
     * else the env-backed config. Every reader resolves it this way, the
     * Settings page included. The web client read config alone, so a key
     * pasted on Settings never reached it and, with CORTENDESK_PUBLIC_KEY
     * unset, every session died with LICENSE_MISMATCH (#75).
     *
     * A blank saved value counts as unset. Saving Settings stores every field,
     * so an install saved with empty server fields before the image supplied
     * them would otherwise hand the web client an empty key.
     */
    public static function server(string $name): string
    {
        $saved = trim((string) static::get($name));

        return $saved !== '' ? $saved : trim((string) config('cortendesk.'.$name));
    }

    /**
     * The configured relay pool as an ordered list of ['address' => …, 'geo' => …].
     *
     * Relay membership/selection is owned by the rendezvous server (hbbs), not the
     * console — see docs/relay-protocol.md. These rows document/manage the hbbs
     * `relay-servers` list; the console does not push them to clients. When no list
     * is configured we fall back to the single `relay_server` env/setting so existing
     * single-relay deployments keep working.
     *
     * @return array<int, array{address: string, geo: string}>
     */
    public static function relayServers(): array
    {
        $raw = static::get('relay_servers');

        if ($raw) {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $rows = [];

                foreach ($decoded as $row) {
                    $address = trim((string) ($row['address'] ?? ''));

                    if ($address === '') {
                        continue;
                    }

                    $rows[] = [
                        'address' => $address,
                        'geo' => trim((string) ($row['geo'] ?? '')),
                    ];
                }

                if ($rows !== []) {
                    return $rows;
                }
            }
        }

        $single = trim((string) static::get('relay_server', config('cortendesk.relay_server')));

        return $single === '' ? [] : [['address' => $single, 'geo' => '']];
    }
}
