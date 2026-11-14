<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Support\Csv;
use App\Support\SessionTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Shuchkin\SimpleXLSXGen;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Connection time between who connected and what they connected to, over a
 * date range (PR #173, by @Liaders). Counts the same sessions as the Overview
 * and the connection log's path filter: an attempt that never logged in (no
 * From ID) is not a session.
 */
class ConnectionReport extends Component
{
    use AuthorizesConsole, WithPagination;

    protected string $paginationTheme = 'bootstrap';

    /** The connection log's export cap: a workbook is built in memory. */
    public const EXPORT_LIMIT = 10000;

    private const FILTERS = ['device_group_id', 'user_id', 'user_group_id', 'rustdesk_id'];

    public string $dateFrom = '';

    public string $dateTo = '';

    /** @var array<string,string> */
    public array $source = [];

    /** @var array<string,string> */
    public array $target = [];

    public function mount(): void
    {
        $this->authorizeConsole('audit');
        $this->period('this');
    }

    public function period(string $period): void
    {
        $start = $period === 'last' ? now()->subMonthNoOverflow()->startOfMonth() : now()->startOfMonth();
        $this->dateFrom = $start->toDateString();
        $this->dateTo = $start->copy()->endOfMonth()->toDateString();
        $this->resetPage();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} start inclusive, end exclusive */
    private function bounds(): array
    {
        $this->authorizeConsole('audit');
        $keys = implode(',', self::FILTERS);
        $this->validate([
            'dateFrom' => 'required|date_format:Y-m-d',
            'dateTo' => 'required|date_format:Y-m-d|after_or_equal:dateFrom',
            'source' => 'array:'.$keys,
            'target' => 'array:'.$keys,
            'source.*' => 'nullable|string|max:255',
            'target.*' => 'nullable|string|max:255',
        ]);

        return [
            CarbonImmutable::parse($this->dateFrom)->startOfDay(),
            CarbonImmutable::parse($this->dateTo)->addDay()->startOfDay(),
        ];
    }

    /** Devices this user can see, narrowed by one side's filters. */
    private function devices(array $filters = []): Builder
    {
        $q = Device::query()->visibleTo(auth()->user());
        foreach (['device_group_id', 'user_id', 'rustdesk_id'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $q->where($field, $filters[$field]);
            }
        }
        if (($filters['user_group_id'] ?? '') !== '') {
            $q->whereHas('user.groups', fn ($q) => $q->where('user_groups.id', $filters['user_group_id']));
        }

        return $q;
    }

    /** Sessions that overlap the period, to visible targets, from the filtered sources. */
    private function query(): Builder
    {
        [$start, $end] = $this->bounds();
        $q = AuditConnection::query()
            ->whereIn('rustdesk_id', $this->devices($this->target)->select('rustdesk_id'))
            ->whereNotNull('from_peer')
            ->where('from_peer', '!=', '')
            ->where('created_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>', $start));
        if (array_filter($this->source, fn ($v) => $v !== '') !== []) {
            $q->whereIn('from_peer', $this->devices($this->source)->select('rustdesk_id'));
        }

        return $q;
    }

    /** Seconds of a completed session inside the period; 0 while it is open. */
    public static function secondsIn(AuditConnection $row, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $row->closed_at
            ? max(0, min($end->timestamp, $row->closed_at->timestamp) - max($start->timestamp, $row->created_at->timestamp))
            : 0;
    }

    public function export(): StreamedResponse
    {
        [$start, $end] = $this->bounds();
        $rows = $this->query()->orderBy('created_at')->orderBy('id')->limit(self::EXPORT_LIMIT)->get();

        return response()->streamDownload(function () use ($rows, $start, $end) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['From ID', 'From name', 'To ID', 'Started', 'Closed', 'Seconds in period', 'Type', 'Note']);
            foreach ($rows as $row) {
                fputcsv($out, Csv::row([
                    $row->from_peer, $row->from_name, $row->rustdesk_id,
                    $row->created_at?->toDateTimeString(), $row->closed_at?->toDateTimeString(),
                    $row->closed_at ? self::secondsIn($row, $start, $end) : '',
                    AuditConnection::englishTypeLabel((int) $row->conn_type), $row->note,
                ]));
            }
            fclose($out);
        }, 'connection-report.csv');
    }

    /**
     * Totals and breakdowns over every matching session. Only the columns the
     * sums need are loaded, a chunk at a time.
     */
    private function reportData(): array
    {
        [$start, $end] = $this->bounds();
        $devices = $this->devices()
            ->select(['id', 'rustdesk_id', 'alias', 'hostname', 'user_id', 'device_group_id'])
            ->with(['user:id,username', 'user.groups:id,name', 'group:id,name'])
            ->get()
            ->keyBy('rustdesk_id');
        $total = $active = $count = 0;
        $intervals = $breakdown = $operators = [];
        $rows = $this->query()->select(['id', 'rustdesk_id', 'from_peer', 'created_at', 'closed_at']);
        foreach ($rows->lazyById(1000) as $row) {
            $count++;
            if (! $row->closed_at) {
                $active++;

                continue;
            }
            $seconds = self::secondsIn($row, $start, $end);
            $total += $seconds;
            // Merge overlaps per owner across their devices, else per source device.
            $device = $devices->get($row->from_peer);
            $operator = $device?->user_id ? 'user:'.$device->user_id : 'device:'.$row->from_peer;
            $intervals[$operator][] = [max($start->timestamp, $row->created_at->timestamp), min($end->timestamp, $row->closed_at->timestamp)];
            $operators[$operator] ??= ['name' => $device?->user?->username ?? $row->from_peer, 'count' => 0, 'seconds' => 0];
            $operators[$operator]['count']++;
            $operators[$operator]['seconds'] += $seconds;
            $group = $devices->get($row->rustdesk_id)?->group;
            $key = $group?->id ?? 0;
            $breakdown[$key] ??= ['name' => $group?->name ?? __('report.ungrouped'), 'count' => 0, 'seconds' => 0];
            $breakdown[$key]['count']++;
            $breakdown[$key]['seconds'] += $seconds;
        }
        uasort($breakdown, fn ($a, $b) => $b['seconds'] <=> $a['seconds']);
        uasort($operators, fn ($a, $b) => $b['seconds'] <=> $a['seconds']);

        return [
            'devices' => $devices, 'total' => $total, 'active' => $active, 'count' => $count,
            'unique' => SessionTime::withoutOverlaps($intervals), 'breakdown' => $breakdown,
            'start' => $start, 'end' => $end, 'operators' => $operators,
        ];
    }

    public function exportXlsx(): StreamedResponse
    {
        $data = $this->reportData();
        // "\0" makes SimpleXLSXGen store the value as literal text: no formulas,
        // markup or links from client data.
        $text = fn ($value) => "\0".(string) $value;
        $duration = fn (int $seconds) => '<style nf="[h]:mm:ss">'.($seconds / 86400).'</style>';
        $heading = fn (array $cells) => array_map(fn ($cell) => '<b>'.$cell.'</b>', $cells);
        $summary = [
            $heading(['Connection report', 'Value']),
            ['From date', $text($this->dateFrom)],
            ['To date (inclusive)', $text($this->dateTo)],
            ['Timezone', $text(config('app.timezone'))],
            ['Source filters', $text(json_encode($this->source, JSON_UNESCAPED_UNICODE))],
            ['Target filters', $text(json_encode($this->target, JSON_UNESCAPED_UNICODE))],
            ['Sessions', $data['count']],
            ['Unfinished sessions (excluded from time totals)', $data['active']],
            ['Total session time', $duration($data['total'])],
            ['Time without overlaps per operator', $duration($data['unique'])],
            ['Calculation', $text(__('report.help', ['timezone' => config('app.timezone')], 'en'))],
            ['Attribution', $text(__('report.attribution', [], 'en'))],
        ];
        if ($data['count'] > self::EXPORT_LIMIT) {
            $summary[] = ['Sessions sheet', $text('First '.self::EXPORT_LIMIT.' of '.$data['count'].' sessions')];
        }
        $xlsx = SimpleXLSXGen::fromArray($summary, 'Summary')->setColWidth(1, 48)->setColWidth(2, 100)->freezePanes('A2');
        foreach (['breakdown' => 'Target groups', 'operators' => 'Operators'] as $key => $name) {
            $rows = [$heading([$name, 'Completed sessions', 'Total session time'])];
            foreach ($data[$key] as $row) {
                $rows[] = [$text($row['name']), $row['count'], $duration($row['seconds'])];
            }
            $xlsx->addSheet($rows, $name)->setColWidth(1, 40)->setColWidth(2, 22)->setColWidth(3, 24)->freezePanes('A2');
        }
        $sessions = [$heading(['From ID', 'From name', 'To ID', 'Started', 'Closed', 'Time in period', 'Type', 'Note'])];
        $rows = $this->query()->orderBy('created_at')->orderBy('id')->limit(self::EXPORT_LIMIT)->get();
        foreach ($rows as $row) {
            $sessions[] = [$text($row->from_peer), $text($row->from_name), $text($row->rustdesk_id),
                $row->created_at?->toDateTimeString(), $row->closed_at?->toDateTimeString(),
                $row->closed_at ? $duration(self::secondsIn($row, $data['start'], $data['end'])) : null,
                $text(AuditConnection::englishTypeLabel((int) $row->conn_type)), $text($row->note)];
        }
        $xlsx->addSheet($sessions, 'Sessions')->freezePanes('A2')->autoFilter('A1:H'.count($sessions))
            ->setColWidth('A:C', 24)->setColWidth('D:E', 22)->setColWidth('F:G', 22)->setColWidth('H', 60);

        return response()->streamDownload(function () use ($xlsx) {
            echo (string) $xlsx;
        }, 'connection-report.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function render()
    {
        try {
            $data = $this->reportData() + [
                'rows' => $this->query()->orderByDesc('created_at')->orderByDesc('id')->paginate(25),
            ];
        } catch (ValidationException $e) {
            // Mid-edit dates (the end before the start): show the field error
            // over an empty report rather than failing the render.
            $this->setErrorBag($e->validator->errors());
            $now = CarbonImmutable::now();
            $data = [
                'devices' => $this->devices()->with(['user.groups', 'group'])->get()->keyBy('rustdesk_id'),
                'total' => 0, 'active' => 0, 'count' => 0, 'unique' => 0, 'breakdown' => [], 'operators' => [],
                'start' => $now, 'end' => $now, 'rows' => new LengthAwarePaginator([], 0, 25),
            ];
        }

        return view('livewire.connection-report', $data);
    }
}
