<?php

namespace App\Contracts;

interface IdServerConsole
{
    /** Send one command to the ID server's console; its reply, or null when it cannot be reached. */
    public function command(string $command): ?string;
}
