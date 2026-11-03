<?php

namespace App\Livewire;

use App\Support\Label;
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
     * Every action the console records, for the filter dropdown. The part
     * before the first dot picks the group (AREAS). A test fails when code
     * records an action that is missing here (issue #108).
     *
     * @var array<string, string>
     */
    public const ACTIONS = [
        'account.update' => 'Profile updated',
        'account.password' => 'Password changed',
        'address-book.create' => 'Address book created',
        'address-book.update' => 'Address book updated',
        'address-book.delete' => 'Address book deleted',
        'address-book.rule-add' => 'Share rule added',
        'address-book.rule-update' => 'Share rule updated',
        'address-book.rule-delete' => 'Share rule deleted',
        'address-book.tag-add' => 'Tag added',
        'address-book.tag-update' => 'Tag updated',
        'address-book.tag-delete' => 'Tag deleted',
        'address-book.password-use' => 'Password used by the web client',
        'address-book.peer-add' => 'Entry added',
        'address-book.peer-update' => 'Entry updated',
        'address-book.peer-delete' => 'Entry removed',
        'api-token.create' => 'API token created',
        'api-token.revoke' => 'API token revoked',
        'backup.create' => 'Backup created',
        'backup.download' => 'Backup downloaded',
        'backup.restore' => 'Backup restored',
        'backup.delete' => 'Backup deleted',
        'backup.settings' => 'Backup settings changed',
        'client_download.create' => 'Download uploaded',
        'client_download.update' => 'Download updated',
        'client_download.publish' => 'Download published',
        'client_download.unpublish' => 'Download unpublished',
        'client_download.delete' => 'Download deleted',
        'device.create' => 'Device created',
        'device.update' => 'Device updated',
        'device.approve' => 'Device approved',
        'device.reject' => 'Device rejected',
        'device.enable' => 'Device enabled',
        'device.disable' => 'Device disabled',
        'device.initiate' => 'Session start permission changed',
        'device.assign' => 'Device assigned',
        'device.group-move' => 'Devices moved to a group',
        'device.duplicate-dismiss' => 'Duplicate flag dismissed',
        'device.export' => 'Devices exported',
        'device.delete' => 'Device deleted',
        'device.restore' => 'Device restored',
        'device.destroy' => 'Device destroyed',
        'group.create' => 'Group created',
        'group.update' => 'Group updated',
        'group.delete' => 'Group deleted',
        'logs.prune' => 'Logs pruned',
        'role.create' => 'Role created',
        'role.update' => 'Role updated',
        'role.delete' => 'Role deleted',
        'session.disconnect' => 'Session disconnected',
        'settings.update' => 'Settings updated',
        'settings.server-access' => 'Server access changed',
        'settings.server-ip-match' => 'Server IP matching changed',
        'settings.mail-test' => 'Test email sent',
        'settings.notification-test' => 'Test notification sent',
        'settings.presence-snooze' => 'Presence alerts snoozed',
        'settings.presence-snooze-clear' => 'Presence snooze cleared',
        'setup.complete' => 'Setup completed',
        'setup.dismiss' => 'Setup guide dismissed',
        'strategy.create' => 'Strategy created',
        'strategy.update' => 'Strategy updated',
        'strategy.toggle' => 'Strategy enabled/disabled',
        'strategy.rollback' => 'Strategy rolled back',
        'strategy.assign' => 'Strategy assigned',
        'strategy.delete' => 'Strategy deleted',
        'user.create' => 'User created',
        'user.update' => 'User updated',
        'user.enable' => 'User enabled',
        'user.disable' => 'User disabled',
        'user.approve' => 'SSO user approved',
        'user.force-logout' => 'User force-logout',
        'user.assign-devices' => 'Devices assigned to user',
        'user.invite' => 'Invitation sent',
        'user.invite-resend' => 'Invitation resent',
        'user.invite-revoke' => 'Invitation revoked',
        'user.invite-accept' => 'Invitation accepted',
        'user.2fa-enable' => 'Two-factor enabled',
        'user.2fa-disable' => 'Two-factor disabled',
        'user.2fa-recovery-regenerate' => 'Recovery codes regenerated',
        'user.2fa-reset' => 'Two-factor reset',
        'user.delete' => 'User deleted',
    ];

    /** Dropdown group per action prefix, in display order. */
    public const AREAS = [
        'user' => 'Users',
        'account' => 'Own account',
        'device' => 'Devices',
        'group' => 'Groups',
        'role' => 'Roles',
        'address-book' => 'Address books',
        'strategy' => 'Strategies',
        'session' => 'Sessions',
        'backup' => 'Backups',
        'client_download' => 'Client downloads',
        'api-token' => 'API tokens',
        'settings' => 'Settings',
        'setup' => 'Setup',
        'logs' => 'Logs',
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
            // A known action matches exactly: 'user.invite' must not also
            // pull in invite-resend and invite-revoke. Anything else typed
            // into the URL is a substring search.
            ->when($this->action !== '', fn (Builder $q) => array_key_exists($this->action, self::ACTIONS)
                ? $q->where('action', $this->action)
                : $q->where('action', 'like', '%'.$this->action.'%'))
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

    /** @return array<string, array<string, string>> group label => [action => label] */
    public static function groupedActions(): array
    {
        $groups = array_fill_keys(array_values(self::AREAS), []);
        foreach (self::ACTIONS as $action => $label) {
            $area = self::AREAS[strstr($action, '.', true)] ?? 'Other';
            $groups[$area][$action] = $label;
        }

        return array_filter($groups);
    }

    public function render()
    {
        return view('livewire.console-audit-list', [
            'audits' => $this->query()->paginate($this->perPage),
            'actionGroups' => collect(self::groupedActions())
                ->mapWithKeys(fn ($actions, $area) => [
                    Label::get('audit_area', $area, $area) => collect($actions)
                        ->map(fn ($label, $code) => Label::get('audit_action', $code, $label))->all(),
                ])->all(),
        ]);
    }
}
