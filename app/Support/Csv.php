<?php

namespace App\Support;

/**
 * CSV export rows. Device names, hostnames, file paths and session notes come
 * from remote clients; a cell starting with = + - @ or a tab/CR runs as a
 * formula when the file is opened in Excel or Sheets. Such cells get a
 * leading apostrophe, which spreadsheets show as plain text.
 */
class Csv
{
    /**
     * @param  array<int, mixed>  $cells
     * @return array<int, mixed>
     */
    public static function row(array $cells): array
    {
        return array_map(
            fn ($cell) => is_string($cell) && $cell !== '' && str_contains("=+-@\t\r", $cell[0]) ? "'".$cell : $cell,
            $cells,
        );
    }
}
