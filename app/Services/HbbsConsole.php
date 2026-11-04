<?php

namespace App\Services;

use App\Contracts\IdServerConsole;

/**
 * hbbs's runtime console, on the NAT-test port (21115): like hbbr's
 * (HbbrConsole), one text command per connection from 127.0.0.1, one reply
 * line, then hbbs closes it. Reaches the ID server bundled in this container.
 */
class HbbsConsole implements IdServerConsole
{
    public function __construct(private int $port = 21115, private float $timeout = 0.5) {}

    public function command(string $command): ?string
    {
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $error, $this->timeout);
        if (! is_resource($socket)) {
            return null;
        }

        stream_set_timeout($socket, 2);
        fwrite($socket, $command);
        $reply = stream_get_contents($socket);
        fclose($socket);

        return $reply === false ? null : $reply;
    }
}
