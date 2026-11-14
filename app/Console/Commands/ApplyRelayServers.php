<?php

namespace App\Console\Commands;

use App\Services\AdvertisedRelays;
use Illuminate\Console\Command;

/**
 * Give the bundled ID server the relay list saved on Settings → Server (#181).
 * The container runs this at every start, before hbbs, so the list is right
 * after an upgrade or a restored database too.
 */
class ApplyRelayServers extends Command
{
    protected $signature = 'cortendesk:relay-servers';

    protected $description = 'Give the bundled ID server the relay list saved in Settings';

    public function handle(AdvertisedRelays $relays): int
    {
        if (! AdvertisedRelays::available()) {
            $this->line('The ID server does not run in this container; nothing to do.');

            return self::SUCCESS;
        }

        $relays->apply();
        $saved = $relays->saved();
        $this->line('[cortendesk] clients are told the relay is at '.(implode(', ', $relays->addresses()) ?: '(none)')
            .($saved === [] ? '' : ' (saved in Settings, Server)'));

        return self::SUCCESS;
    }
}
