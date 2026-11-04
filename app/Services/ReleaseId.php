<?php

namespace App\Services;

use App\Contracts\IdServerConsole;

/**
 * Frees a RustDesk ID on the bundled ID server (CortenDesk Server 1.4.0
 * `release-id`), so a reinstalled machine can register it again. hbbs
 * deletes the peer from its database and memory and closes its live
 * registration. The console's own device record is left as it is.
 */
class ReleaseId
{
    public const RELEASED = 'released';

    public const RELEASED_ONLINE = 'released_online';

    public const NOT_FOUND = 'not_found';

    public const UNAVAILABLE = 'unavailable';

    public const FAILED = 'failed';

    public function __construct(private IdServerConsole $console) {}

    /** Only the ID server in this container can be reached over loopback. */
    public static function available(): bool
    {
        return (bool) config('cortendesk.embedded_server');
    }

    /** @return string one of the constants above */
    public function release(string $id): string
    {
        if (! self::available() || ! preg_match('/^[\w.@-]{1,253}$/', $id)) {
            return self::UNAVAILABLE;
        }

        $reply = $this->console->command('release-id '.$id);
        if ($reply === null || trim($reply) === '') {
            return self::UNAVAILABLE;
        }

        return match (true) {
            trim($reply) === 'OK '.$id => self::RELEASED,
            trim($reply) === 'OK '.$id.' online' => self::RELEASED_ONLINE,
            str_starts_with(trim($reply), 'NOT_FOUND') => self::NOT_FOUND,
            default => self::FAILED,
        };
    }
}
