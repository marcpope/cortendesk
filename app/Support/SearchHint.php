<?php

namespace App\Support;

/**
 * Placeholder text for search boxes that password managers can't read as a
 * sign-in field.
 *
 * iCloud Passwords ignores autocomplete="off" and the other opt-outs and
 * offers credentials in any box whose placeholder says "username" or
 * "usuario" (#125). A word joiner (U+2060) after each word's first letter
 * leaves the text looking the same but breaks the keyword match.
 */
final class SearchHint
{
    public const JOINER = "\u{2060}";

    public static function placeholder(string $text): string
    {
        return preg_replace('/(?<!\p{L})(\p{L})(?=\p{L})/u', '$1'.self::JOINER, $text) ?? $text;
    }
}
