<?php

use App\Http\Controllers\Api\ClientOidcController;
use App\Http\Controllers\Api\WebClientController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientDownloadController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OidcController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\WebClientPageController;
use Illuminate\Support\Facades\Route;

// Web-client bootstrap script (loaded by the static V1 web client pre-login)
Route::get('/webclient-config/index.js', [WebClientController::class, 'configJs']);

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');

    // Console 2FA challenge (PLAN B6). Reached only with a pending-2FA session
    // marker; CSRF-protected by the web group (do NOT exclude these).
    Route::get('/login/2fa', [AuthController::class, 'showTwoFactorChallenge'])->name('login.2fa');
    Route::post('/login/2fa', [AuthController::class, 'twoFactorChallenge'])->name('login.2fa.attempt');

    // Emailed new-device sign-in code (PLAN D1). Reached only with a pending
    // email_verify session marker; same CSRF rules as the 2FA challenge.
    Route::get('/login/email', [AuthController::class, 'showEmailChallenge'])->name('login.email');
    Route::post('/login/email', [AuthController::class, 'emailChallenge'])->name('login.email.attempt');
    Route::post('/login/email/resend', [AuthController::class, 'resendEmailCode'])->name('login.email.resend');

    // Invitation acceptance (PLAN D1). In the guest group so the no-session
    // case is guaranteed to work and an already-signed-in session can never be
    // silently swapped for a new identity (test your own invite in a private
    // window). The token is the credential; the throttle bounds guessing.
    Route::get('/invite/{token}', [InvitationController::class, 'show'])
        ->middleware('throttle:20,1')->name('invite.show');
    Route::post('/invite/{token}', [InvitationController::class, 'accept'])
        ->middleware('throttle:20,1')->name('invite.accept');

    // Self-service password reset. Guest-only: a signed-in user has no need of
    // it, and it must never swap the current session for another identity.
    // Throttled hard — the request form takes a username or email, so it is the
    // one place an outsider can probe for accounts.
    Route::get('/forgot-password', [PasswordResetController::class, 'showRequest'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showReset'])
        ->middleware('throttle:20,1')->name('password.reset');
    Route::post('/reset-password/{token}', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:20,1')->name('password.update');

    // OIDC single sign-on (PLAN D3). The callback is a GET the provider
    // redirects the browser to, so it carries no CSRF token of ours — the
    // `state` parameter checked in OidcService::exchange() is the CSRF guard.
    Route::get('/login/oidc', [OidcController::class, 'redirect'])->name('login.oidc');
    Route::get('/login/oidc/callback', [OidcController::class, 'callback'])->name('login.oidc.callback');
});

// Browser half of the RustDesk-client SSO flow (PLAN D3, spec §6–7). Opened by
// the client in the system browser, so it must work whether or not that browser
// happens to have a console session — hence outside both guest and auth groups.
// The `state` parameter is the CSRF guard, matched against the pending row.
Route::get('/login/oidc/client-callback',
    [ClientOidcController::class, 'browserCallback'])
    ->name('login.oidc.client-callback');

// Custom client installers. Outside both guest and auth groups on purpose: the
// page is meant to be linked to somebody who has no console account, and a
// technician at a fresh machine may already be signed in on their own laptop —
// neither case should be redirected. Only published rows are visible, the
// controller streams the bytes as an attachment, and the throttle bounds a
// scraper (the files are the expensive part, not the page).
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/downloads', [ClientDownloadController::class, 'index'])->name('downloads.index');
    Route::get('/downloads/{download}', [ClientDownloadController::class, 'show'])->name('downloads.show');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // My Account — own profile, password and 2FA, for every user (PLAN A6).
    Route::view('/account', 'account.index')->name('account');

    // Two-Factor Authentication on its own URL (enrollment wizard, PLAN B6).
    // Kept separate because the enforcement middleware sends un-enrolled users
    // straight here, with nothing else on the page to distract from enrolling.
    Route::view('/account/two-factor', 'account.two-factor')->name('account.two-factor');

    Route::get('/', [DashboardController::class, 'index'])->name('overview');

    // Console sections are gated per area by the delegated-role matrix (PLAN
    // D4). `console-can` returns true for is_admin and, for a user with no
    // role, for exactly the areas a non-admin could always reach — so these
    // lines are behaviour-identical on an install with no roles defined.
    Route::view('/devices', 'devices.index')->name('devices')
        ->middleware('console-can:device,r');
    Route::view('/address-books', 'address-books.index')->name('address-books')
        ->middleware('console-can:address_book,r');

    // Native in-browser RustDesk client (full-viewport standalone page; assets in public/rdclient/)
    Route::get('/webclient', [WebClientPageController::class, 'show'])->name('webclient');

    // Operational logs — the baseline every user has always had.
    Route::middleware('console-can:audit,r')->group(function () {
        Route::view('/logs/connections', 'logs.connections')->name('logs.connections');
        Route::view('/logs/file-transfers', 'logs.file-transfers')->name('logs.file-transfers');
        Route::view('/logs/alarms', 'logs.alarms')->name('logs.alarms');
    });

    // Sections that used to be flatly admin-only, now delegatable per area.
    Route::view('/groups', 'groups.index')->name('groups')
        ->middleware('console-can:group,r');
    Route::view('/strategies', 'strategies.index')->name('strategies')
        ->middleware('console-can:strategy,r');
    Route::view('/users', 'users.index')->name('users')
        ->middleware('console-can:user,r');
    Route::view('/settings', 'settings.index')->name('settings')
        ->middleware('console-can:setting,r');

    // Uploading the installers the public /downloads page hands out. Gated by
    // the `setting` area rather than an area of its own — see the
    // ClientDownloadManager docblock for why.
    Route::view('/client-downloads', 'client-downloads.index')->name('client-downloads')
        ->middleware('console-can:setting,r');

    // Login history and the console audit trail are the sensitive half of the
    // `audit` area, so they need "Manage" rather than "View".
    Route::middleware('console-can:audit,rw')->group(function () {
        Route::view('/logs/logins', 'logs.logins')->name('logs.logins');
        Route::view('/logs/console', 'logs.console')->name('logs.console');
    });

    // Role administration is super-admin only, full stop: a delegated admin who
    // could edit roles could grant themselves anything.
    Route::middleware('admin')->group(function () {
        Route::view('/roles', 'roles.index')->name('roles');
    });
});
