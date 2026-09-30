<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\LoginLog;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LoginLogList extends Component
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
     * Login history is part of the sensitive half of the audit area (PLAN D4).
     * /livewire/update is reachable directly, so the component guards itself
     * rather than trusting the route it happened to be rendered under.
     */
    public function mount(): void
    {
        $this->authorizeConsole('audit', 'rw');
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
        return LoginLog::query()
            ->when($this->search !== '', function (Builder $q) {
                $s = '%'.$this->search.'%';
                $q->where(function (Builder $q) use ($s) {
                    $q->where('username', 'like', $s)
                        ->orWhere('device_id', 'like', $s)
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
            fputcsv($out, ['When', 'Username', 'Client', 'Device ID', 'Device OS', 'IP', 'Result', 'Note']);
            foreach ($rows as $row) {
                fputcsv($out, Csv::row([
                    $row->created_at?->toDateTimeString(),
                    $row->username,
                    $row->client,
                    $row->device_id,
                    $row->device_os,
                    $row->ip,
                    $row->successful ? 'Success' : 'Failed',
                    $row->note,
                ]));
            }
            fclose($out);
        }, 'login-log.csv');
    }

    public function render()
    {
        return view('livewire.login-log-list', [
            'logs' => $this->query()->paginate($this->perPage),
        ]);
    }
}
