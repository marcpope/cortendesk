<?php

namespace App\Services;

use App\Contracts\RelayConsole;

/**
 * hbbr's runtime console: a TCP connection from 127.0.0.1 to the relay port
 * carries one text command ("sb 500", or "sb" to read the value back) and
 * gets the reply, then hbbr closes it. Only loopback connections are treated
 * as commands, so this works for the relay bundled in this container.
 */
class HbbrConsole implements RelayConsole
{
    public function __construct(private int $port = 21117, private float $timeout = 0.5) {}

    public function command(string $command): ?string
    {
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $error, $this->timeout);
        if (! is_resource($socket)) {
            return null;
        }

        stream_set_timeout($socket, 1);
        fwrite($socket, $command);
        $reply = stream_get_contents($socket);
        fclose($socket);

        return $reply === false ? null : $reply;
    }
}
