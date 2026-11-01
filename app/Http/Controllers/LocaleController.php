<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /locale: the language menu in the top bar and on the sign-in page.
 *
 * The choice is kept in the session (so it works before signing in and carries
 * over the login) and, for a signed-in user, saved on the account like the
 * theme, so it holds in every browser.
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(User::LOCALES))],
        ]);

        $request->session()->put('locale', $validated['locale']);
        $request->user()?->forceFill(['locale' => $validated['locale']])->save();

        // Back to the page the menu was on, but only on this host: the Referer
        // header is the client's to set.
        $back = url()->previous();
        $sameHost = parse_url($back, PHP_URL_HOST) === $request->getHost();

        return redirect()->to($sameHost ? $back : route('login'));
    }
}
