<?php

namespace App\Contracts;

interface RelayConsole
{
    /** Send one command to the relay's console; its reply, or null when it cannot be reached. */
    public function command(string $command): ?string;
}
