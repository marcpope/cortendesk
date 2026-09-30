<?php

use App\Http\Controllers\AdminApi\AddressBooksController;
use App\Http\Controllers\AdminApi\AuditsController;
use App\Http\Controllers\AdminApi\DeviceGroupsController;
use App\Http\Controllers\AdminApi\DevicesController;
use App\Http\Controllers\AdminApi\UserGroupsController;
use App\Http\Controllers\AdminApi\UsersController;
use App\Http\Controllers\Api\AddressBookController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\ClientAuthController;
use App\Http\Controllers\Api\ClientOidcController;
use App\Http\Controllers\Api\GroupTabController;
use App\Http\Controllers\Api\ServerLinkController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Middleware\VerifyServerSecret;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RustDesk client API
|--------------------------------------------------------------------------
| Implements the HTTP contract the official RustDesk client speaks when
| configured with this server as its "API Server".
| Spec: docs/client-api.md (extracted from rustdesk/rustdesk source).
|
| Contract reminders:
| - Tokenless endpoints (login, heartbeat, sysinfo, audit) get no Bearer.
| - Many callers ignore HTTP status and only parse the body; errors are
|   signaled as {"error": "..."} even on HTTP 200.
| - New-AB mutations signal success with an EMPTY 200 body.
*/

Route::get('/version', fn () => response(config('cortendesk.api_version')));

// ---- Tokenless (rate-limited) ------------------------------------------
Route::middleware('throttle:240,1')->group(function () {
    Route::match(['get', 'head'], '/login-options', [ClientAuthController::class, 'loginOptions']);
    Route::post('/login', [ClientAuthController::class, 'login'])->middleware('throttle:20,1');

    // Client SSO (spec §6–7). Tokenless: the app has no token yet. The poll is
    // deliberately generous — the client polls once a second for 180 seconds.
    Route::post('/oidc/auth', [ClientOidcController::class, 'auth'])->middleware('throttle:20,1');
    Route::get('/oidc/auth-query', [ClientOidcController::class, 'authQuery'])->middleware('throttle:400,1');

    Route::post('/heartbeat', [SyncController::class, 'heartbeat']);
    Route::post('/sysinfo', [SyncController::class, 'sysinfo']);

    Route::post('/audit/conn', [AuditController::class, 'connection']);
    Route::post('/audit/file', [AuditController::class, 'file']);
    Route::post('/audit/alarm', [AuditController::class, 'alarm']);
});

// ---- Bearer-token endpoints ---------------------------------------------
Route::middleware('auth:client')->group(function () {
    Route::post('/logout', [ClientAuthController::class, 'logout']);
    Route::post('/currentUser', [ClientAuthController::class, 'currentUser']);
    Route::put('/audit', [AuditController::class, 'note']);
    Route::get('/audit/conn/active', [AuditController::class, 'activeConnection']);

    // Legacy address book (client < 1.2.6, Sciter)
    Route::get('/ab', [AddressBookController::class, 'legacyGet']);
    Route::post('/ab/get', [AddressBookController::class, 'legacyGet']); // Sciter alias
    Route::post('/ab', [AddressBookController::class, 'legacyPush']);

    // New multi-address-book API (client >= 1.2.6)
    Route::post('/ab/personal', [AddressBookController::class, 'personal']);
    Route::post('/ab/settings', [AddressBookController::class, 'settings']);
    Route::post('/ab/shared/profiles', [AddressBookController::class, 'sharedProfiles']);
    Route::post('/ab/peers', [AddressBookController::class, 'peers']);
    Route::post('/ab/tags/{guid}', [AddressBookController::class, 'tags']);
    Route::post('/ab/peer/add/{guid}', [AddressBookController::class, 'peerAdd']);
    Route::put('/ab/peer/update/{guid}', [AddressBookController::class, 'peerUpdate']);
    Route::delete('/ab/peer/{guid}', [AddressBookController::class, 'peerDelete']);
    Route::post('/ab/tag/add/{guid}', [AddressBookController::class, 'tagAdd']);
    Route::put('/ab/tag/rename/{guid}', [AddressBookController::class, 'tagRename']);
    Route::put('/ab/tag/update/{guid}', [AddressBookController::class, 'tagUpdate']);
    Route::delete('/ab/tag/{guid}', [AddressBookController::class, 'tagDelete']);

    // Web client bootstrap (V1 static client served at /webclient/)
    Route::match(['get', 'post'], '/server-config', [\App\Http\Controllers\Api\WebClientController::class, 'serverConfig']);

    // Group tab
    Route::get('/device-group/accessible', [GroupTabController::class, 'deviceGroups']);
    Route::get('/users', [GroupTabController::class, 'users']);
    Route::get('/peers', [GroupTabController::class, 'peers']);
});

/*
|--------------------------------------------------------------------------
| Server link (CortenDesk Server 1.1.0+)
|--------------------------------------------------------------------------
| Called by hbbs, not by clients: it pulls the device policy and reports LAN
| addresses. Bearer = CORTENDESK_SERVER_SECRET. Spec: docs/server-link.md.
*/
Route::middleware([VerifyServerSecret::class, 'throttle:600,1'])
    ->prefix('server')
    ->group(function () {
        Route::get('/policy', [ServerLinkController::class, 'policy']);
        Route::post('/local-addrs', [ServerLinkController::class, 'localAddrs']);
    });

/*
|--------------------------------------------------------------------------
| Admin automation REST API (v1)
|--------------------------------------------------------------------------
| Scoped Bearer tokens from `api_tokens` (guard: auth:api-token). Distinct
| from the tokenless client protocol and the auth:client guard above. Each
| route declares the permission it needs via `api-token-can:<resource>,<lvl>`;
| the token's per-resource matrix (none|r|rw) gates access. Envelope:
| {code,data,message}. Rate-limited. Spec: docs/admin-api.md. (PLAN B1.)
*/
Route::middleware(['auth:api-token', 'throttle:120,1'])
    ->prefix('v1')
    ->group(function () {
        // Users --------------------------------------------------------------
        Route::get('/users', [UsersController::class, 'index'])->middleware('api-token-can:user,r');
        Route::get('/users/{user}', [UsersController::class, 'show'])->middleware('api-token-can:user,r');
        Route::post('/users', [UsersController::class, 'store'])->middleware('api-token-can:user,rw');
        Route::post('/users/{user}/enable', [UsersController::class, 'enable'])->middleware('api-token-can:user,rw');
        Route::post('/users/{user}/disable', [UsersController::class, 'disable'])->middleware('api-token-can:user,rw');
        Route::post('/users/{user}/force-logout', [UsersController::class, 'forceLogout'])->middleware('api-token-can:user,rw');
        Route::delete('/users/{user}', [UsersController::class, 'destroy'])->middleware('api-token-can:user,rw');

        // Devices ------------------------------------------------------------
        Route::get('/devices', [DevicesController::class, 'index'])->middleware('api-token-can:device,r');
        Route::get('/devices/{device}', [DevicesController::class, 'show'])->middleware('api-token-can:device,r');
        Route::post('/devices/{device}/enable', [DevicesController::class, 'enable'])->withTrashed()->middleware('api-token-can:device,rw');
        Route::post('/devices/{device}/disable', [DevicesController::class, 'disable'])->middleware('api-token-can:device,rw');
        Route::post('/devices/{device}/assign', [DevicesController::class, 'assign'])->middleware('api-token-can:device,rw');
        Route::delete('/devices/{device}', [DevicesController::class, 'destroy'])->withTrashed()->middleware('api-token-can:device,rw');

        // User groups --------------------------------------------------------
        Route::get('/user-groups', [UserGroupsController::class, 'index'])->middleware('api-token-can:group,r');
        Route::get('/user-groups/{userGroup}', [UserGroupsController::class, 'show'])->middleware('api-token-can:group,r');
        Route::post('/user-groups', [UserGroupsController::class, 'store'])->middleware('api-token-can:group,rw');
        Route::put('/user-groups/{userGroup}', [UserGroupsController::class, 'update'])->middleware('api-token-can:group,rw');
        Route::delete('/user-groups/{userGroup}', [UserGroupsController::class, 'destroy'])->middleware('api-token-can:group,rw');
        Route::post('/user-groups/{userGroup}/members', [UserGroupsController::class, 'addMember'])->middleware('api-token-can:group,rw');
        Route::delete('/user-groups/{userGroup}/members', [UserGroupsController::class, 'removeMember'])->middleware('api-token-can:group,rw');

        // Device groups ------------------------------------------------------
        Route::get('/device-groups', [DeviceGroupsController::class, 'index'])->middleware('api-token-can:group,r');
        Route::get('/device-groups/{deviceGroup}', [DeviceGroupsController::class, 'show'])->middleware('api-token-can:group,r');
        Route::post('/device-groups', [DeviceGroupsController::class, 'store'])->middleware('api-token-can:group,rw');
        Route::put('/device-groups/{deviceGroup}', [DeviceGroupsController::class, 'update'])->middleware('api-token-can:group,rw');
        Route::delete('/device-groups/{deviceGroup}', [DeviceGroupsController::class, 'destroy'])->middleware('api-token-can:group,rw');
        Route::post('/device-groups/{deviceGroup}/members', [DeviceGroupsController::class, 'addMember'])->middleware('api-token-can:group,rw');
        Route::delete('/device-groups/{deviceGroup}/members', [DeviceGroupsController::class, 'removeMember'])->middleware('api-token-can:group,rw');

        // Address books ------------------------------------------------------
        Route::get('/address-books', [AddressBooksController::class, 'index'])->middleware('api-token-can:address_book,r');
        Route::get('/address-books/{addressBook}', [AddressBooksController::class, 'show'])->middleware('api-token-can:address_book,r');
        Route::post('/address-books', [AddressBooksController::class, 'store'])->middleware('api-token-can:address_book,rw');
        Route::put('/address-books/{addressBook}', [AddressBooksController::class, 'update'])->middleware('api-token-can:address_book,rw');
        Route::delete('/address-books/{addressBook}', [AddressBooksController::class, 'destroy'])->middleware('api-token-can:address_book,rw');

        Route::get('/address-books/{addressBook}/peers', [AddressBooksController::class, 'peers'])->middleware('api-token-can:address_book,r');
        Route::post('/address-books/{addressBook}/peers', [AddressBooksController::class, 'storePeer'])->middleware('api-token-can:address_book,rw');
        Route::put('/address-books/{addressBook}/peers/{peer}', [AddressBooksController::class, 'updatePeer'])->middleware('api-token-can:address_book,rw');
        Route::delete('/address-books/{addressBook}/peers/{peer}', [AddressBooksController::class, 'destroyPeer'])->middleware('api-token-can:address_book,rw');

        Route::get('/address-books/{addressBook}/tags', [AddressBooksController::class, 'tags'])->middleware('api-token-can:address_book,r');
        Route::post('/address-books/{addressBook}/tags', [AddressBooksController::class, 'storeTag'])->middleware('api-token-can:address_book,rw');
        Route::put('/address-books/{addressBook}/tags/{tag}', [AddressBooksController::class, 'updateTag'])->middleware('api-token-can:address_book,rw');
        Route::delete('/address-books/{addressBook}/tags/{tag}', [AddressBooksController::class, 'destroyTag'])->middleware('api-token-can:address_book,rw');

        Route::get('/address-books/{addressBook}/rules', [AddressBooksController::class, 'rules'])->middleware('api-token-can:address_book,r');
        Route::post('/address-books/{addressBook}/rules', [AddressBooksController::class, 'storeRule'])->middleware('api-token-can:address_book,rw');
        Route::put('/address-books/{addressBook}/rules/{rule}', [AddressBooksController::class, 'updateRule'])->middleware('api-token-can:address_book,rw');
        Route::delete('/address-books/{addressBook}/rules/{rule}', [AddressBooksController::class, 'destroyRule'])->middleware('api-token-can:address_book,rw');

        // Audits (read-only) -------------------------------------------------
        Route::get('/audits/conn', [AuditsController::class, 'connection'])->middleware('api-token-can:audit,r');
        Route::get('/audits/file', [AuditsController::class, 'file'])->middleware('api-token-can:audit,r');
        Route::get('/audits/alarm', [AuditsController::class, 'alarm'])->middleware('api-token-can:audit,r');
        Route::get('/audits/console', [AuditsController::class, 'console'])->middleware('api-token-can:audit,r');
    });
