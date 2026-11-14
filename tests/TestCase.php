<?php

namespace Tests;

use App\Contracts\IdServerConsole;
use App\Contracts\RelayConsole;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * A test must never talk to a real hbbs or hbbr listening on this machine:
     * both consoles are down unless the test binds a fake.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $down = new class implements IdServerConsole, RelayConsole
        {
            public function command(string $command): ?string
            {
                return null;
            }
        };
        $this->app->instance(IdServerConsole::class, $down);
        $this->app->instance(RelayConsole::class, $down);
    }

    /**
     * RefreshDatabase wipes the database, so refuse anything that is not a MySQL/MariaDB test database.
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! in_array($connection, ['mysql', 'mariadb'], true) || ! str_ends_with($database, '_test')) {
            throw new RuntimeException("Tests need a MySQL/MariaDB database whose name ends in _test, got {$connection}:{$database}.");
        }

        return parent::setUpTraits();
    }
}
