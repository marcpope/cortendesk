<?php

namespace App\Services;

use App\Contracts\RelayConsole;
use App\Models\Setting;

/**
 * Bandwidth limits of the relay (hbbr) bundled in the container (#128).
 *
 * hbbr caps every relayed session and the relay as a whole, and slows a
 * session down once it has run long and fast. The defaults (128 Mb/s per
 * session, 1024 Mb/s in total, 32 Mb/s after 30 minutes above 66%) cut a
 * large file transfer to a fraction of a gigabit line.
 *
 * A save is applied twice: pushed to the running relay over its console, so
 * new sessions get it at once, and written to the .env file hbbr reads from
 * its data directory at start, so it survives a restart. That file takes
 * precedence over SINGLE_BANDWIDTH and friends in the container environment.
 * Sessions already running keep the per-session limit they started with.
 */
class RelayLimits
{
    public const SETTING = 'relay_limits';

    public const ENV_FILE = '.env';

    /** Ten years: hbbr has no switch for the slowdown, only a start time. */
    private const NEVER_SECONDS = 315_360_000;

    /** Form field => [hbbr console command, .env name]. */
    private const FIELDS = [
        'single' => ['sb', 'SINGLE_BANDWIDTH'],
        'total' => ['tb', 'TOTAL_BANDWIDTH'],
        'slowAfter' => ['t', 'DOWNGRADE_START_CHECK'],
        'slowAbove' => ['dt', 'DOWNGRADE_THRESHOLD'],
        'slowTo' => ['ls', 'LIMIT_SPEED'],
    ];

    /** hbbr's built-in values, in the form's units (Mb/s, minutes, percent). */
    public const DEFAULTS = [
        'single' => 128,
        'total' => 1024,
        'slowDown' => true,
        'slowAfter' => 30,
        'slowAbove' => 66,
        'slowTo' => 32,
    ];

    public const RULES = [
        'single' => 'required|integer|min:1|max:100000',
        'total' => 'required|integer|min:1|max:100000',
        'slowDown' => 'boolean',
        'slowAfter' => 'required|integer|min:1|max:10080',
        'slowAbove' => 'required|integer|min:1|max:100',
        'slowTo' => 'required|integer|min:1|max:100000',
    ];

    public function __construct(private RelayConsole $console) {}

    /** The bundled relay runs here, so there is something to configure. */
    public static function available(): bool
    {
        return (bool) config('cortendesk.embedded_server') && (string) config('cortendesk.rustdesk_dir') !== '';
    }

    /**
     * The saved limits, or null before the first save.
     *
     * @return array{single:int,total:int,slowDown:bool,slowAfter:int,slowAbove:int,slowTo:int}|null
     */
    public function saved(): ?array
    {
        $stored = json_decode((string) Setting::get(self::SETTING, ''), true);

        return is_array($stored) ? self::normalize($stored) : null;
    }

    /**
     * What the running relay uses, read back over its console; null when it
     * does not answer.
     *
     * @return array{single:int,total:int,slowDown:bool,slowAfter:int,slowAbove:int,slowTo:int}|null
     */
    public function live(): ?array
    {
        $values = [];
        foreach (self::FIELDS as $field => [$command]) {
            $reply = $this->console->command($command);
            if ($reply === null || ! preg_match('/^\s*([0-9.]+)/', $reply, $m)) {
                return null;
            }
            $values[$field] = (float) $m[1];
        }

        $seconds = (int) $values['slowAfter'];

        return [
            'single' => (int) round($values['single']),
            'total' => (int) round($values['total']),
            'slowDown' => $seconds < self::NEVER_SECONDS,
            'slowAfter' => $seconds < self::NEVER_SECONDS ? max(1, (int) round($seconds / 60)) : self::DEFAULTS['slowAfter'],
            'slowAbove' => (int) round($values['slowAbove'] * 100),
            'slowTo' => (int) round($values['slowTo']),
        ];
    }

    /**
     * Store, write the .env file and push to the running relay. Returns
     * whether the relay took the new values now; false means they apply at
     * its next start.
     *
     * @param  array<string, mixed>  $limits
     */
    public function save(array $limits): bool
    {
        $limits = self::normalize($limits);
        Setting::put(self::SETTING, json_encode($limits));
        $this->writeEnvFile($limits);

        $pushed = true;
        foreach (self::hbbrValues($limits) as $field => $value) {
            $pushed = $this->console->command(self::FIELDS[$field][0].' '.$value) !== null && $pushed;
        }

        return $pushed;
    }

    /**
     * @param  array<string, mixed>  $limits
     * @return array{single:int,total:int,slowDown:bool,slowAfter:int,slowAbove:int,slowTo:int}
     */
    public static function normalize(array $limits): array
    {
        $out = [];
        foreach (self::DEFAULTS as $field => $default) {
            $out[$field] = is_bool($default)
                ? (bool) ($limits[$field] ?? $default)
                : max(1, (int) ($limits[$field] ?? $default));
        }
        $out['slowAbove'] = min(100, $out['slowAbove']);

        return $out;
    }

    /**
     * The limits in hbbr's own units: Mb/s, seconds and a fraction.
     *
     * The threshold is sent a touch high because hbbr stores it as
     * (value * 100) truncated to an integer, and 0.29 * 100 is 28.999…
     *
     * @param  array{single:int,total:int,slowDown:bool,slowAfter:int,slowAbove:int,slowTo:int}  $limits
     * @return array<string, string>
     */
    private static function hbbrValues(array $limits): array
    {
        return [
            'single' => (string) $limits['single'],
            'total' => (string) $limits['total'],
            'slowAfter' => (string) ($limits['slowDown'] ? $limits['slowAfter'] * 60 : self::NEVER_SECONDS),
            'slowAbove' => sprintf('%.3f', ($limits['slowAbove'] + 0.1) / 100),
            'slowTo' => (string) $limits['slowTo'],
        ];
    }

    /**
     * Rewrite our five lines of the relay's .env, keeping any other lines an
     * operator put there.
     *
     * @param  array{single:int,total:int,slowDown:bool,slowAfter:int,slowAbove:int,slowTo:int}  $limits
     */
    private function writeEnvFile(array $limits): void
    {
        $dir = (string) config('cortendesk.rustdesk_dir');
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        $path = $dir.'/'.self::ENV_FILE;
        $ours = array_column(self::FIELDS, 1);
        $kept = [];
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $name = strtoupper(trim(strtok($line, '=') ?: ''));
                if (! in_array(str_replace('-', '_', $name), $ours, true)) {
                    $kept[] = $line;
                }
            }
        }
        while ($kept !== [] && trim(end($kept)) === '') {
            array_pop($kept);
        }

        $lines = $kept;
        foreach (self::hbbrValues($limits) as $field => $value) {
            $lines[] = self::FIELDS[$field][1].'='.$value;
        }

        file_put_contents($path.'.tmp', implode("\n", $lines)."\n");
        rename($path.'.tmp', $path);
    }
}
