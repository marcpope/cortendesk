<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\ConsoleAudit;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConsoleAuditList extends Component
{
    use AuthorizesConsole, WithPagination;

    protected string $paginationTheme = 'bootstrap';

    /**
     * Known audit actions, grouped for the filter dropdown. Free-text actions
     * that are not in this map still match via the plain LIKE fallback.
     *
     * @var array<string, string>
     */
    public const ACTIONS = [
        'user.create' => 'User created',
        'user.update' => 'User updated',
        'user.enable' => 'User enabled',
        'user.disable' => 'User disabled',
        'user.force-logout' => 'User force-logout',
        'user.delete' => 'User deleted',
        'device.create' => 'Device created',
        'device.update' => 'Device updated',
        'device.delete' => 'Device deleted',
        'device.restore' => 'Device restored',
        'device.destroy' => 'Device destroyed',
        'group.create' => 'Group created',
        'group.update' => 'Group updated',
        'group.delete' => 'Group deleted',
        'address-book.create' => 'Address book created',
        'address-book.delete' => 'Address book deleted',
        'address-book.rule-add' => 'Address book rule added',
        'address-book.rule-update' => 'Address book rule updated',
        'address-book.rule-delete' => 'Address book rule deleted',
        'address-book.update' => 'Address book updated',
        'address-book.tag-add' => 'Address book tag added',
        'address-book.tag-delete' => 'Address book tag deleted',
        'address-book.peer-add' => 'Address book entry added',
        'address-book.peer-update' => 'Address book entry updated',
        'address-book.peer-delete' => 'Address book entry removed',
        'strategy.create' => 'Strategy created',
        'strategy.update' => 'Strategy updated',
        'strategy.toggle' => 'Strategy enabled/disabled',
        'strategy.delete' => 'Strategy deleted',
        'strategy.assign' => 'Strategy assigned',
        'settings.update' => 'Settings updated',
        'settings.server-access' => 'Server access changed',
        'settings.server-ip-match' => 'Server IP matching changed',
        'logs.prune' => 'Logs pruned',
    ];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $dateFrom = '';

    #[Url(except: '')]
    public string $dateTo = '';

    public int $perPage = 20;

    /**
     * Console audit trail is part of the sensitive half of the audit area (PLAN D4).
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

    public function updatedAction(): void
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
        $this->reset('search', 'action', 'dateFrom', 'dateTo');
        $this->resetPage();
    }

    protected function query(): Builder
    {
        return ConsoleAudit::query()
            ->with('user')
            ->when($this->search !== '', function (Builder $q) {
                $s = '%'.$this->search.'%';
                $q->where(function (Builder $q) use ($s) {
                    $q->where('username', 'like', $s)
                        ->orWhere('summary', 'like', $s);
                });
            })
            ->when($this->action !== '', fn (Builder $q) => $q->where('action', 'like', '%'.$this->action.'%'))
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
            fputcsv($out, ['When', 'Operator', 'Action', 'Target Type', 'Target', 'Details', 'IP']);
            foreach ($rows as $row) {
                fputcsv($out, Csv::row([
                    $row->created_at?->toDateTimeString(),
                    $row->username,
                    $row->action,
                    $row->target_type,
                    $row->target_id,
                    $row->summary,
                    $row->ip,
                ]));
            }
            fclose($out);
        }, 'console-audit.csv');
    }

    public function render()
    {
        return view('livewire.console-audit-list', [
            'audits' => $this->query()->paginate($this->perPage),
            'actions' => self::ACTIONS,
        ]);
    }
}
