<?php

// Password managers read a search box whose placeholder says "username" or
// "usuario" as a sign-in field and offer credentials in it (#125, iCloud
// Passwords in Brave, Spanish). Every search input opts out.
it('marks every search box as not a sign-in field', function () {
    $missing = [];
    $searched = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));
    foreach ($files as $file) {
        if (!str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }
        preg_match_all('/<input[^>]*type="search"[^>]*>/s', file_get_contents($file->getPathname()), $inputs);
        $searched += count($inputs[0]);
        foreach ($inputs[0] as $input) {
            if (!str_contains($input, 'autocomplete="off"') || !str_contains($input, 'data-1p-ignore')
                || !str_contains($input, 'SearchHint::placeholder(')) {
                $missing[] = $file->getFilename();
            }
        }
    }
    expect($searched)->toBeGreaterThan(5)
        ->and($missing)->toBe([]);
});

// iCloud Passwords ignores those opt-outs and matches the placeholder text.
it('hides sign-in words in search placeholders without changing the text', function () {
    foreach (['en' => 'users.search_username_name_email', 'es' => 'devices.search_id_alias_hostname_user'] as $locale => $key) {
        $text = trans($key, [], $locale);
        $hint = App\Support\SearchHint::placeholder($text);

        expect(mb_strtolower($hint))->not->toContain('username')->not->toContain('usuario')
            ->and(str_replace(App\Support\SearchHint::JOINER, '', $hint))->toBe($text);
    }
});
