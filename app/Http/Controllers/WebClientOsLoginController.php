<?php

namespace App\Http\Controllers;

use App\Models\ConsoleAudit;
use App\Models\WebClientOsLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class WebClientOsLoginController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        if ($problem = $this->secureTransportProblem($request)) {
            return $problem;
        }
        $validated = $this->validateInput($request, false);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $enabled = WebClientOsLogin::query()
            ->where('user_id', $request->user()->id)
            ->where('peer_id', $validated['peerId'])
            ->exists();

        return $this->noStoreJson(['enabled' => $enabled]);
    }

    public function update(Request $request): Response|JsonResponse
    {
        if ($problem = $this->secureTransportProblem($request)) {
            return $problem;
        }
        $validated = $this->validateInput($request, true, true);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }
        if ($problem = $this->reauthenticate($request, $validated['currentPassword'])) {
            return $problem;
        }

        DB::transaction(function () use ($request, $validated): void {
            WebClientOsLogin::query()->updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'peer_id' => $validated['peerId'],
                ],
                ['password' => $validated['password']],
            );
            ConsoleAudit::record(
                'webclient.os-login.save',
                'Saved OS auto-login for peer '.$validated['peerId'],
                'peer',
                $validated['peerId'],
            );
        });

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }

    public function reveal(Request $request): JsonResponse
    {
        if ($problem = $this->secureTransportProblem($request)) {
            return $problem;
        }
        $validated = $this->validateInput($request, false, true);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }
        if ($problem = $this->reauthenticate($request, $validated['currentPassword'])) {
            return $problem;
        }

        $login = WebClientOsLogin::query()
            ->where('user_id', $request->user()->id)
            ->where('peer_id', $validated['peerId'])
            ->first();
        if (! $login) {
            return $this->noStoreJson(['error' => 'OS auto-login is not configured.'], 404);
        }

        ConsoleAudit::record(
            'webclient.os-login.reveal',
            'Revealed OS auto-login for peer '.$validated['peerId'],
            'peer',
            $validated['peerId'],
        );

        return $this->noStoreJson(['password' => $login->password]);
    }

    public function destroy(Request $request): Response|JsonResponse
    {
        if ($problem = $this->secureTransportProblem($request)) {
            return $problem;
        }
        $validated = $this->validateInput($request, false, true);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }
        if ($problem = $this->reauthenticate($request, $validated['currentPassword'])) {
            return $problem;
        }

        DB::transaction(function () use ($request, $validated): void {
            WebClientOsLogin::query()
                ->where('user_id', $request->user()->id)
                ->where('peer_id', $validated['peerId'])
                ->delete();
            ConsoleAudit::record(
                'webclient.os-login.delete',
                'Deleted OS auto-login for peer '.$validated['peerId'],
                'peer',
                $validated['peerId'],
            );
        });

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }

    private function reauthenticate(Request $request, string $currentPassword): ?JsonResponse
    {
        $user = $request->user();
        if ($user->isSsoProvisioned() || ! Hash::check($currentPassword, $user->password)) {
            return $this->noStoreJson(['error' => 'Current CortenDesk password is required.'], 403);
        }

        return null;
    }

    private function secureTransportProblem(Request $request): ?JsonResponse
    {
        $configuredScheme = strtolower((string) parse_url((string) config('app.url'), PHP_URL_SCHEME));
        if ($request->isSecure() && $configuredScheme === 'https') {
            return null;
        }

        $loopbackHost = in_array(strtolower($request->getHost()), ['localhost', '127.0.0.1', '::1'], true);
        $loopbackIp = in_array($request->ip(), ['127.0.0.1', '::1'], true);
        if ($loopbackHost && $loopbackIp) {
            return null;
        }

        return $this->noStoreJson(['error' => 'HTTPS is required for OS auto-login.'], 403);
    }

    /** @return array{peerId:string,password?:string,currentPassword?:string}|JsonResponse */
    private function validateInput(
        Request $request,
        bool $withPassword,
        bool $withCurrentPassword = false,
    ): array|JsonResponse {
        $rules = [
            'peerId' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9_-]+\z/'],
        ];
        if ($withPassword) {
            $rules['password'] = ['required', 'string', 'min:1', 'max:1024'];
        }
        if ($withCurrentPassword) {
            $rules['currentPassword'] = ['required', 'string', 'min:1', 'max:1024'];
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->noStoreJson(['error' => 'Invalid OS auto-login settings.'], 422);
        }

        /** @var array{peerId:string,password?:string,currentPassword?:string} $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /** @param  array<string, mixed>  $body */
    private function noStoreJson(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store, private');
    }
}
