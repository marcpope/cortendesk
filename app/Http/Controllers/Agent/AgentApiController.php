<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\AgentCommands;
use App\Services\AgentReports;
use App\Support\Monitoring;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The CortenDesk Agent API, /agent/v1 (docs/monitoring/SPEC.md "Agent API").
 * Unlike the RustDesk client API, the agent reads HTTP status codes, so
 * errors use real ones with {"error": "<code>"}.
 */
class AgentApiController extends Controller
{
    public function __construct(private AgentReports $reports, private AgentCommands $commands) {}

    public function enroll(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'token' => 'required|string|max:100',
            'machine_uuid' => 'required|string|max:255',
            'os' => 'nullable|string|in:windows,darwin,linux',
            'os_version' => 'nullable|string|max:255',
            'arch' => 'nullable|string|max:16',
        ] + $this->identityRules());
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $result = $this->reports->enroll($data['token'], $data, $request->ip());
        if (! $result) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        [$agent, $secret] = $result;

        return response()->json([
            'agent_id' => $agent->public_id,
            'secret' => $secret,
            'interval' => $agent->enabled ? Monitoring::interval() : Monitoring::DISABLED_INTERVAL,
        ], 201);
    }

    public function report(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        if (! $agent->enabled) {
            return $this->disabled();
        }

        $data = $this->validated($request, [
            'uptime' => 'nullable|numeric|min:0',
            'cpu' => 'nullable|array',
            'cpu.percent' => 'nullable|numeric',
            'cpu.cores' => 'nullable|integer|min:0',
            'cpu.load' => 'nullable|array|max:3',
            'memory' => 'nullable|array',
            'disks' => 'nullable|array|max:64',
            'disks.*' => 'array',
            'network' => 'nullable|array',
            'rustdesk' => 'nullable|array',
            'users' => 'nullable|array|max:32',
            'reboot_pending' => 'nullable|boolean',
            'inventory_hash' => 'nullable|string|max:64',
            'health' => 'nullable|array',
            'capabilities' => 'nullable|array|max:32',
            'capabilities.*' => 'string|max:32',
            'trial' => 'nullable|boolean',
        ] + $this->identityRules());
        if ($data instanceof JsonResponse) {
            return $data;
        }

        // A downloaded update proving it can report before it is swapped
        // in. Nothing is stored and no commands go to it.
        if ($data['trial'] ?? false) {
            return response()->json(['interval' => Monitoring::interval(), 'inventory' => false, 'watch_services' => []]);
        }

        $wantsInventory = $this->reports->report($agent, $data, $request->ip());

        $body = [
            'interval' => Monitoring::interval(),
            'inventory' => $wantsInventory,
            'watch_services' => array_values((array) $agent->watch_services),
        ];
        if ($commands = $this->commands->deliver($agent)) {
            $body['commands'] = $commands;
        }

        return response()->json($body);
    }

    public function commandResult(Request $request, string $id): JsonResponse
    {
        $agent = $this->agent($request);

        $data = $this->validated($request, [
            'status' => 'required|string|in:running,succeeded,failed',
            'exit_code' => 'nullable|integer',
            'output' => 'nullable|string|max:65536',
            'error' => 'nullable|string|max:4096',
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        if (! $this->commands->result($agent, $id, $data)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json((object) []);
    }

    public function inventory(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        if (! $agent->enabled) {
            return $this->disabled();
        }

        $data = $this->validated($request, [
            'hash' => 'required|string|size:64',
            'os' => 'nullable|array',
            'hardware' => 'nullable|array',
            'network' => 'nullable|array|max:256',
            'software' => 'nullable|array',
            'local_admins' => 'nullable|array|max:64',
            'battery' => 'nullable|array',
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $this->reports->inventory($agent, $data);

        return response()->json((object) []);
    }

    private function agent(Request $request): Agent
    {
        return $request->attributes->get('agent');
    }

    private function disabled(): JsonResponse
    {
        return response()->json(['error' => 'disabled', 'interval' => Monitoring::DISABLED_INTERVAL], 403);
    }

    private function identityRules(): array
    {
        return [
            'machine_uuid' => 'nullable|string|max:255',
            'rustdesk_id' => 'nullable|string|max:64',
            'hostname' => 'nullable|string|max:255',
            'agent_version' => 'nullable|string|max:32',
        ];
    }

    /** Validated input, or a 422 in the agent API's error shape. */
    private function validated(Request $request, array $rules): array|JsonResponse
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['error' => 'invalid', 'fields' => $validator->errors()->keys()], 422);
        }

        return $validator->validated() + $request->only(['cpu', 'memory', 'disks', 'network', 'rustdesk', 'os', 'hardware', 'software', 'health', 'local_admins', 'battery']);
    }
}
