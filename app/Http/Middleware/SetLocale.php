<?php

namespace App\Http\Middleware;

use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the console language: the signed-in user's saved choice, else the
 * language-menu choice kept in the session, else the browser's Accept-Language, else APP_LOCALE. Anything not in User::LOCALES
 * is ignored, so a stale or hand-edited value falls back to English.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(User::LOCALES);
        // Signed-in choice, then the one picked in the language menu before
        // signing in, then the browser, then APP_LOCALE.
        $candidates = [$request->user()?->locale, $request->hasSession() ? $request->session()->get('locale') : null];

        // getPreferredLanguage() answers with the first entry when nothing in
        // Accept-Language matches (or there is no header), so APP_LOCALE goes
        // first to be that answer.
        $default = in_array(config('app.locale'), $supported, true) ? config('app.locale') : 'en';
        $ordered = array_values(array_unique([$default, ...$supported]));

        $locale = collect($candidates)->first(fn ($code) => in_array($code, $supported, true))
            ?? ($request->getPreferredLanguage($ordered) ?: $default);

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
