<?php

namespace App\Http\Controllers\AdminApi;

use App\Models\AlarmLog;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\ConsoleAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only audit queries mirroring the console log screens: connection,
 * file-transfer, alarm and console-action logs.
 */
class AuditsController extends AdminApiController
{
    /** GET /api/v1/audits/conn — filters: id, peer, action, date_from, date_to. */
    public function connection(Request $request): JsonResponse
    {
        $rows = AuditConnection::query()
            ->when($request->filled('id'), fn ($q) => $q->where('rustdesk_id', 'like', '%'.$request->query('id').'%'))
            ->when($request->filled('peer'), fn ($q) => $q->where(fn ($q) => $q
                ->where('from_peer', 'like', '%'.$request->query('peer').'%')
                ->orWhere('from_name', 'like', '%'.$request->query('peer').'%')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->query('action')))
            ->tap(fn ($q) => $this->dateRange($q, $request))
            ->with('noteUser')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows, fn (AuditConnection $r) => [
            'id' => $r->id,
            'action' => $r->action,
            'rustdesk_id' => $r->rustdesk_id,
            'from_peer' => $r->from_peer,
            'from_name' => $r->from_name,
            'conn_type' => $r->conn_type,
            'ip' => $r->ip,
            'session_id' => $r->session_id,
            'created_at' => $r->created_at?->toIso8601String(),
            'closed_at' => $r->closed_at?->toIso8601String(),
            'note' => $r->note,
            'note_by' => $r->noteUser?->username,
            'noted_at' => $r->noted_at?->toIso8601String(),
        ]);
    }

    /** GET /api/v1/audits/file — filters: id, peer, direction, date_from, date_to. */
    public function file(Request $request): JsonResponse
    {
        $rows = AuditFileTransfer::query()
            ->when($request->filled('id'), fn ($q) => $q->where('rustdesk_id', 'like', '%'.$request->query('id').'%'))
            ->when($request->filled('peer'), fn ($q) => $q->where(fn ($q) => $q
                ->where('from_peer', 'like', '%'.$request->query('peer').'%')
                ->orWhere('from_name', 'like', '%'.$request->query('peer').'%')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->query('direction')))
            ->tap(fn ($q) => $this->dateRange($q, $request))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows, fn (AuditFileTransfer $r) => [
            'id' => $r->id,
            'rustdesk_id' => $r->rustdesk_id,
            'from_peer' => $r->from_peer,
            'from_name' => $r->from_name,
            'path' => $r->path,
            'direction' => $r->direction,
            'is_file' => (bool) $r->is_file,
            'file_count' => $r->file_count,
            'ip' => $r->ip,
            'created_at' => $r->created_at?->toIso8601String(),
        ]);
    }

    /** GET /api/v1/audits/alarm — filters: id, typ, date_from, date_to. */
    public function alarm(Request $request): JsonResponse
    {
        $rows = AlarmLog::query()
            ->when($request->filled('id'), fn ($q) => $q->where('rustdesk_id', 'like', '%'.$request->query('id').'%'))
            ->when($request->filled('typ'), fn ($q) => $q->where('typ', (int) $request->query('typ')))
            ->tap(fn ($q) => $this->dateRange($q, $request))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows, fn (AlarmLog $r) => [
            'id' => $r->id,
            'rustdesk_id' => $r->rustdesk_id,
            'typ' => $r->typ,
            'type_label' => $r->typeLabel(),
            'info' => $r->info,
            'created_at' => $r->created_at?->toIso8601String(),
        ]);
    }

    /** GET /api/v1/audits/console — filters: action, operator (search), date_from, date_to. */
    public function console(Request $request): JsonResponse
    {
        $rows = ConsoleAudit::query()
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', '%'.$request->query('action').'%'))
            ->when($request->filled('operator'), fn ($q) => $q->where(fn ($q) => $q
                ->where('username', 'like', '%'.$request->query('operator').'%')
                ->orWhere('summary', 'like', '%'.$request->query('operator').'%')))
            ->tap(fn ($q) => $this->dateRange($q, $request))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows, fn (ConsoleAudit $r) => [
            'id' => $r->id,
            'username' => $r->username,
            'action' => $r->action,
            'target_type' => $r->target_type,
            'target_id' => $r->target_id,
            'summary' => $r->summary,
            'ip' => $r->ip,
            'created_at' => $r->created_at?->toIso8601String(),
        ]);
    }

    private function dateRange(Builder $q, Request $request): void
    {
        $q->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->query('date_to')));
    }
}
