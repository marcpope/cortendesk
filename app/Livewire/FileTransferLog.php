<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\AuditFileTransfer;
use App\Models\Device;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileTransferLog extends Component
{
    use AuthorizesConsole, WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $dateFrom = '';

    #[Url(except: '')]
    public string $dateTo = '';

    public int $perPage = 20;

    /**
     * File transfers log is part of the operational log screens (PLAN D4).
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

        return AuditFileTransfer::query()
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
                        ->orWhere('path', 'like', $s)
                        ->orWhere('ip', 'like', $s);
                });
            })
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
            fputcsv($out, ['When', 'Device', 'From ID', 'From Name', 'Direction', 'Path', 'Files', 'IP']);
            foreach ($rows as $row) {
                fputcsv($out, Csv::row([
                    $row->created_at?->toDateTimeString(),
                    $row->rustdesk_id,
                    $row->from_peer,
                    $row->from_name,
                    $row->direction === 1 ? 'Receive' : 'Send',
                    $row->path,
                    $row->file_count,
                    $row->ip,
                ]));
            }
            fclose($out);
        }, 'file-transfer-log.csv');
    }

    public function render()
    {
        return view('livewire.file-transfer-log', [
            'transfers' => $this->query()->paginate($this->perPage),
        ]);
    }
}
