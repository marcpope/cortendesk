<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConnectionLog extends Component
{
    use AuthorizesConsole, WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public const TYPES = AuditConnection::TYPE_LABELS;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $dateFrom = '';

    #[Url(except: '')]
    public string $dateTo = '';

    public int $perPage = 20;

    /**
     * Connections log is part of the operational log screens (PLAN D4).
     * /livewire/update is reachable directly, so the component guards itself
     * rather than trusting the route it happened to be rendered under.
     */
    public function mount(): void
    {
        $this->authorizeConsole('audit', 'r');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'dateFrom', 'dateTo');
        $this->resetPage();
    }

    protected function query(): Builder
    {
        $user = auth()->user();

        return AuditConnection::query()
            ->when(! $user->seesAllDevices(), fn (Builder $q) => $q->whereIn(
                'rustdesk_id',
                Device::query()->visibleTo($user)->pluck('rustdesk_id')
            ))
            ->when($this->search !== '', function (Builder $q) {
                $s = '%'.$this->search.'%';
                $q->where(function (Builder $q) use ($s) {
                    $q->where('rustdesk_id', 'like', $s)
                        ->orWhere('from_peer', 'like', $s)
                        ->orWhere('from_name', 'like', $s)
                        ->orWhere('ip', 'like', $s)
                        ->orWhere('note', 'like', $s);
                });
            })
            ->when($this->dateFrom !== '', fn (Builder $q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn (Builder $q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function export(): StreamedResponse
    {
        $rows = $this->query()->with('noteUser')->limit(10000)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When', 'Controlled Device', 'From ID', 'From Name', 'Type', 'IP', 'Closed At', 'Duration (s)', 'Note', 'Note By']);
            foreach ($rows as $row) {
                fputcsv($out, Csv::row([
                    $row->created_at?->toDateTimeString(),
                    $row->rustdesk_id,
                    $row->from_peer,
                    $row->from_name,
                    AuditConnection::typeLabel((int) $row->conn_type),
                    $row->ip,
                    $row->closed_at?->toDateTimeString() ?? 'active',
                    $row->closed_at ? $row->created_at->diffInSeconds($row->closed_at) : '',
                    $row->note,
                    $row->noteUser?->username,
                ]));
            }
            fclose($out);
        }, 'connection-log.csv');
    }

    public function render()
    {
        return view('livewire.connection-log', [
            'connections' => $this->query()->with('noteUser')->paginate($this->perPage),
        ]);
    }
}
