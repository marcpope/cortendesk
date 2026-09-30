<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\AlarmLog;
use App\Models\Device;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AlarmLogList extends Component
{
    use AuthorizesConsole, WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $dateFrom = '';

    #[Url(except: '')]
    public string $dateTo = '';

    public int $perPage = 20;

    /**
     * Alarms log is part of the operational log screens (PLAN D4).
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

    public function updatedType(): void
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
        $this->reset('search', 'type', 'dateFrom', 'dateTo');
        $this->resetPage();
    }

    protected function query(): Builder
    {
        $user = auth()->user();

        return AlarmLog::query()
            ->when(! $user->seesAllDevices(), fn (Builder $q) => $q->whereIn(
                'rustdesk_id',
                Device::query()->visibleTo($user)->pluck('rustdesk_id')
            ))
            ->when($this->search !== '', function (Builder $q) {
                $s = '%'.$this->search.'%';
                $q->where(function (Builder $q) use ($s) {
                    $q->where('rustdesk_id', 'like', $s)
                        ->orWhere('info', 'like', $s);
                });
            })
            ->when($this->type !== '', fn (Builder $q) => $q->where('typ', (int) $this->type))
            ->when($this->dateFrom !== '', fn (Builder $q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn (Builder $q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function export(): StreamedResponse
    {
        $rows = $this->query()->limit(10000)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When', 'Device', 'Type', 'Info', 'Conn ID']);
            foreach ($rows as $row) {
                fputcsv($out, Csv::row([
                    $row->created_at?->toDateTimeString(),
                    $row->rustdesk_id,
                    $row->typeLabel(),
                    $row->info,
                    $row->conn_id,
                ]));
            }
            fclose($out);
        }, 'alarm-log.csv');
    }

    public function render()
    {
        return view('livewire.alarm-log-list', [
            'alarms' => $this->query()->paginate($this->perPage),
            'types' => AlarmLog::TYPES,
        ]);
    }
}
