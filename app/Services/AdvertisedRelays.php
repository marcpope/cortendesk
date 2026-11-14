<?php

namespace App\Services;

use App\Contracts\IdServerConsole;
use App\Models\Setting;
use App\Support\ServerEnvFile;

/**
 * The relay addresses the ID server bundled in the container hands to clients
 * (#181).
 *
 * hbbs names the relay for every session. It gets the list saved on Settings
 * → Server: the relay pool, or the relay field when the pool is empty. With
 * neither saved it keeps the address from the container environment
 * (CORTENDESK_RELAY_SERVER, by default the console's host on 21117).
 *
 * Like RelayLimits, a save is applied twice: pushed to the running hbbs over
 * its console, and written to the .env file hbbs reads at start, so it
 * survives a restart.
 */
class AdvertisedRelays
{
    public const ENV_NAME = 'RELAY_SERVERS';

    public function __construct(private IdServerConsole $console) {}

    /** The ID server runs in this container, so there is something to configure. */
    public static function available(): bool
    {
        return RelayLimits::available();
    }

    /**
     * The addresses saved on Settings → Server, pool first; empty when
     * nothing is saved.
     *
     * @return list<string>
     */
    public function saved(): array
    {
        $pool = json_decode((string) Setting::get('relay_servers', ''), true);
        $saved = self::valid(array_column(is_array($pool) ? $pool : [], 'address'));

        return $saved ?: self::valid([(string) Setting::get('relay_server', '')]);
    }

    /**
     * What hbbs should hand out: the saved list, or the environment's address.
     *
     * @return list<string>
     */
    public function addresses(): array
    {
        return $this->saved() ?: self::valid([(string) config('cortendesk.relay_server')]);
    }

    /**
     * What the running ID server hands out. With several relays it lists only
     * the ones it can reach. Null when it does not answer.
     *
     * @return list<string>|null
     */
    public function live(): ?array
    {
        $reply = $this->console->command('relay-servers');

        return $reply === null ? null : array_values(array_filter(array_map('trim', explode("\n", $reply))));
    }

    /**
     * Write the saved list to the .env file and push it to the running ID
     * server. Returns whether hbbs took it now; false means it applies at its
     * next start.
     */
    public function apply(): bool
    {
        if (! self::available()) {
            return false;
        }

        $saved = $this->saved();
        ServerEnvFile::write([self::ENV_NAME], $saved === [] ? [] : [self::ENV_NAME => implode(',', $saved)]);

        $addresses = $this->addresses();

        return $addresses !== [] && $this->console->command('relay-servers '.implode(',', $addresses)) !== null;
    }

    /**
     * Host, host:port or [v6]:port. Anything else would break the comma list
     * or the console command, and hbbs would reject it anyway.
     *
     * @param  array<int, mixed>  $addresses
     * @return list<string>
     */
    private static function valid(array $addresses): array
    {
        $addresses = array_map(fn ($a) => trim((string) $a), $addresses);

        return array_values(array_unique(array_filter($addresses, fn ($a) => preg_match('/^[A-Za-z0-9._:\[\]-]+$/', $a) === 1)));
    }
}
