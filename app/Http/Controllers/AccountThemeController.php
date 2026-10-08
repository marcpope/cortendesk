<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * POST /account/theme: the topbar theme switch saves the choice on the user,
 * so it holds in every tab, browser and device (issue #94).
 */
class AccountThemeController extends Controller
{
    public function update(Request $request): Response
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', Rule::in(User::THEMES)],
        ]);

        $request->user()->forceFill(['theme' => $validated['theme']])->save();

        return response()->noContent();
    }
}
