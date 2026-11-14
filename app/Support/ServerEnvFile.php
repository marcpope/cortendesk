<?php

namespace App\Support;

/**
 * The .env file in the bundled server's data directory. hbbs and hbbr read it
 * at every start, and it takes precedence over the container environment.
 */
class ServerEnvFile
{
    public const NAME = '.env';

    /**
     * Rewrite the lines named in $names: set the ones in $values, drop the
     * rest, and keep any other line an operator put there.
     *
     * @param  list<string>  $names
     * @param  array<string, string>  $values
     */
    public static function write(array $names, array $values): void
    {
        $dir = (string) config('cortendesk.rustdesk_dir');
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        $path = $dir.'/'.self::NAME;
        $kept = [];
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $name = strtoupper(trim(strtok($line, '=') ?: ''));
                if (! in_array(str_replace('-', '_', $name), $names, true)) {
                    $kept[] = $line;
                }
            }
        }
        while ($kept !== [] && trim(end($kept)) === '') {
            array_pop($kept);
        }

        $lines = $kept;
        foreach ($values as $name => $value) {
            $lines[] = $name.'='.$value;
        }
        if ($lines === [] && ! is_file($path)) {
            return;
        }

        file_put_contents($path.'.tmp', $lines === [] ? '' : implode("\n", $lines)."\n");
        rename($path.'.tmp', $path);
    }
}
