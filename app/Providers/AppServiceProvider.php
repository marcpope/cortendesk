<?php

namespace App\Providers;

use App\Contracts\HealthProbeLimiter;
use App\Contracts\IdServerConsole;
use App\Contracts\RelayConsole;
use App\Contracts\TcpProbe;
use App\Models\AlarmLog;
use App\Models\ApiToken;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\ClientToken;
use App\Models\ConsoleAudit;
use App\Models\LoginLog;
use App\Services\FileHealthProbeLimiter;
use App\Services\HbbrConsole;
use App\Services\HbbsConsole;
use App\Services\SocketTcpProbe;
use App\Support\AuditLogStream;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TcpProbe::class, SocketTcpProbe::class);
        $this->app->bind(HealthProbeLimiter::class, FileHealthProbeLimiter::class);
        $this->app->bind(RelayConsole::class, HbbrConsole::class);
        $this->app->bind(IdServerConsole::class, HbbsConsole::class);
    }

    /**
     * Copy each audit row to the audit log stream once it is saved
     * (AuditLogStream; a no-op unless CORTENDESK_AUDIT_LOG is on).
     * Sessions are written again when their state changes; closes done in
     * bulk are written by AuditConnection itself.
     */
    private function streamAuditRecords(): void
    {
        ConsoleAudit::created(fn (ConsoleAudit $row) => AuditLogStream::consoleAudit($row));
        LoginLog::created(fn (LoginLog $row) => AuditLogStream::login($row));
        AlarmLog::created(fn (AlarmLog $row) => AuditLogStream::alarm($row));
        AuditFileTransfer::created(fn (AuditFileTransfer $row) => AuditLogStream::fileTransfer($row));
        AuditConnection::created(fn (AuditConnection $row) => AuditLogStream::connection($row, created: true));
        AuditConnection::updated(function (AuditConnection $row) {
            if ($row->wasChanged('action')) {
                AuditLogStream::connection($row);
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->streamAuditRecords();

        // Heartbeat, sysinfo and audit posts from RustDesk clients. A device
        // heartbeats every 15 s, every 3 s during a session (20 a minute), so
        // 120 leaves room. The per-address ceiling is for a whole fleet
        // behind one NAT: about 1,500 idle devices or 300 in sessions.
        RateLimiter::for('device-sync', fn (Request $request) => [
            Limit::perMinute(120)->by('device|'.$request->ip().'|'.(string) $request->input('id')),
            Limit::perMinute(6000)->by('address|'.$request->ip()),
        ]);

        // CortenDesk Agent reports (docs/monitoring). The shortest interval is
        // 30 s, so 10 a minute per agent covers retries; the address ceiling
        // is for a fleet behind one NAT.
        RateLimiter::for('agent', fn (Request $request) => [
            Limit::perMinute(10)->by('agent|'.strtok((string) $request->bearerToken(), '.')),
            Limit::perMinute(6000)->by('agent-address|'.$request->ip()),
        ]);

        // Guard for the RustDesk client API: Authorization: Bearer <token>
        // issued by POST /api/login and stored in client_tokens.
        Auth::viaRequest('client-token', function (Request $request) {
            $bearer = $request->bearerToken();
            if (! $bearer) {
                return null;
            }

            $token = ClientToken::query()->where('token', $bearer)->first();
            if (! $token || ! $token->isValid()) {
                return null;
            }

            if (! $token->user || ! $token->user->is_active) {
                return null;
            }

            $token->forceFill(['last_used_at' => now()])->saveQuietly();
            $request->attributes->set('client_token', $token);

            return $token->user;
        });

        // Guard for the admin automation REST API (routes/api.php "admin-api"
        // group): Authorization: Bearer <cdk_...> from api_tokens. Per-route
        // permission is enforced separately by the api-token-can middleware.
        Auth::viaRequest('api-token', function (Request $request) {
            $bearer = $request->bearerToken();
            if (! $bearer) {
                return null;
            }

            $token = ApiToken::findValid($bearer);
            if (! $token) {
                return null;
            }

            // The creator account must still exist, be active, AND still hold
            // "manage API tokens" (PLAN D4). Admin-API tokens carry the owner's
            // power; if the owner is demoted, their tokens die with the
            // privilege (mirrors the way a disabled user's tokens are revoked).
            // consoleAllows returns true for is_admin, so nothing changes for
            // tokens owned by a full administrator.
            if (! $token->user || ! $token->user->is_active
                || ! $token->user->consoleAllows('token', 'rw')) {
                return null;
            }

            // Usage stamping + permission enforcement live in the
            // api-token-can middleware (reliable regardless of guard caching).
            $request->attributes->set('api_token', $token);

            return $token->user;
        });
    }
}
