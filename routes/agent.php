<?php

use App\Http\Controllers\Agent\AgentApiController;
use App\Http\Controllers\Agent\AgentInstallController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CortenDesk Agent API (/agent/v1)
|--------------------------------------------------------------------------
| Spec: docs/monitoring/SPEC.md. Loaded from bootstrap/app.php behind the
| `monitoring` middleware, so every path here is a 404 while Monitoring is
| off. No session, no CSRF: agents authenticate with a bearer secret.
*/

Route::middleware('throttle:60,1,agent-install')->group(function () {
    Route::get('/install.sh', [AgentInstallController::class, 'sh']);
    Route::get('/install.ps1', [AgentInstallController::class, 'ps1']);
});

Route::post('/enroll', [AgentApiController::class, 'enroll'])->middleware('throttle:20,1,agent-enroll');

Route::middleware([AuthenticateAgent::class, 'throttle:agent'])->group(function () {
    Route::post('/report', [AgentApiController::class, 'report']);
    Route::post('/inventory', [AgentApiController::class, 'inventory']);
    Route::post('/commands/{id}', [AgentApiController::class, 'commandResult'])->where('id', '[0-9A-Za-z]{1,40}');
});
