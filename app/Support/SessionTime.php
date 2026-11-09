<?php

namespace App\Support;

class SessionTime
{
    /** Union of half-open intervals, grouped by operator/device. */
    public static function withoutOverlaps(array $groups): int
    {
        $total = 0;
        foreach ($groups as $intervals) {
            sort($intervals);
            $end = null;
            foreach ($intervals as [$start, $stop]) {
                $total += max(0, $stop - max($start, $end ?? $start));
                $end = max($end ?? $stop, $stop);
            }
        }

        return $total;
    }

    public static function format(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
