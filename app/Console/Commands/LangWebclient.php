<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Writes lang/en/webclient.php to webclient/src/i18n-en.json. The web client
 * bundles it as the English fallback for any key the page did not send, so
 * rebuild the web client after running this. lang:check fails while the two
 * differ.
 *
 *   php artisan lang:webclient
 */
class LangWebclient extends Command
{
    protected $signature = 'lang:webclient';

    protected $description = 'Write the web client English fallback (webclient/src/i18n-en.json) from lang/en/webclient.php';

    public function handle(): int
    {
        $messages = (array) require lang_path('en/webclient.php');
        file_put_contents(
            base_path('webclient/src/i18n-en.json'),
            json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );
        $this->info(count($messages).' strings written to webclient/src/i18n-en.json');

        return self::SUCCESS;
    }
}
