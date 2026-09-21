<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\StrategyRevision;
use App\Models\User;
use App\Services\StrategyCompliance;
use App\Services\StrategyImpact;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Strategies console screen (PLAN C4).
 *
 * The editor only ever offers keys from Strategy::OPTION_KEYS — the same
 * allowlist the delivery engine sanitizes against — so nothing this screen can
 * produce is capable of reaching a device with a key the protocol doc does not
 * document. The catalogue below adds labels and help text on top of that table;
 * it never adds keys.
 *
 * Form convention: an option whose form value is the empty string is NOT part
 * of the strategy. It is stored by omission, not as "". That matters, because ""
 * on the wire means "reset this key to the client's built-in default"
 * (docs/strategy-protocol.md §2.3) and the delivery engine already emits it by
 * itself for keys a device is holding that the strategy has dropped. Letting an
 * operator type an explicit "" as well would give two spellings of one idea.
 */
class StrategyList extends Component
{
    use AuthorizesConsole;

    /** Editor state: null = closed, 0 = creating, >0 = editing that strategy. */
    public ?int $editingId = null;

    public string $formName = '';

    public string $formNote = '';

    public bool $formEnabled = true;

    public bool $formIsDefault = false;

    public bool $formEnforce = false;

    /** Minutes a device may stay unconfirmed after a push before it is stale. */
    public int $formConfirmationTimeout = 15;

    /** @var array<string,string> option key => form value ('' = not managed) */
    public array $formOptions = [];

    public string $revisionNote = '';

    public ?int $historyStrategyId = null;

    public ?int $compareFromRevisionId = null;

    public ?int $compareToRevisionId = null;

    #[Locked]
    public bool $previewing = false;

    /** @var array<string,mixed> */
    #[Locked]
    public array $impactPreview = [];

    /** @var array<string,mixed> */
    #[Locked]
    public array $previewSnapshot = [];

    #[Locked]
    public ?string $previewFingerprint = null;

    #[Locked]
    public ?int $previewTargetId = null;

    #[Locked]
    public ?int $pendingDeleteId = null;

    #[Locked]
    public ?int $restoreRevisionId = null;

    /** Compliance drill-down state: strategy id, or null when closed. */
    public ?int $complianceStrategyId = null;

    public string $complianceState = 'all';

    /** Assignment editor state: strategy id, or null when closed. */
    public ?int $assigningId = null;

    public string $assignTab = 'devices';

    public string $assignSearch = '';

    /** @var array<int,int|string> */
    public array $assignDeviceIds = [];

    /** @var array<int,int|string> */
    public array $assignUserIds = [];

    /** @var array<int,int|string> */
    public array $assignGroupIds = [];

    /** Section titles + the apply-timing caveat that belongs to each group. */
    private const GROUP_META = [
        'permissions' => [
            'title' => 'Permissions',
            'icon' => 'ri-shield-keyhole-line',
            'help' => 'What an incoming session may do. Applied when the next session is authorised — sessions already running keep the values they started with.',
        ],
        'security' => [
            'title' => 'Security & password',
            'icon' => 'ri-lock-password-line',
            'help' => 'How the device authorises incoming connections. Most of these take effect within one heartbeat; the two password-shape options only apply the next time the one-time password is regenerated.',
        ],
        'display' => [
            'title' => 'Capture & display',
            'icon' => 'ri-macbook-line',
            'help' => 'Screen capture and desktop behaviour during a session.',
        ],
        'client' => [
            'title' => 'Client & updates',
            'icon' => 'ri-refresh-line',
            'help' => 'How the RustDesk client maintains itself. Unlike the groups above, these are not about an incoming session.',
        ],
    ];

    /**
     * Labels, help text and enum wording. Keyed by the option key so a key that
     * is not in the allowlist can never gain a control by being described here.
     *
     * @var array<string,array{label:string,help?:string,choices?:array<string,string>}>
     */
    private const OPTION_META = [
        'access-mode' => [
            'label' => 'Access mode',
            'help' => 'Master switch. "Full" forces every permission below on and "View only" forces them all off, whatever the individual controls say. Desktop only — ignored by the Android and iOS clients.',
            'choices' => ['full' => 'Full access', 'view' => 'View only', 'custom' => 'Custom (use the controls below)'],
        ],
        'enable-keyboard' => ['label' => 'Keyboard & mouse'],
        'enable-clipboard' => ['label' => 'Clipboard'],
        'enable-file-transfer' => ['label' => 'File transfer'],
        'enable-audio' => ['label' => 'Audio'],
        'enable-camera' => ['label' => 'Camera'],
        'enable-terminal' => ['label' => 'Terminal'],
        'enable-tunnel' => ['label' => 'TCP tunnelling'],
        'enable-remote-restart' => ['label' => 'Remote restart'],
        'enable-record-session' => ['label' => 'Session recording', 'help' => 'Whether the connecting side is permitted to record.'],
        'enable-block-input' => ['label' => 'Block local input', 'help' => 'Windows only in the client UI.'],
        'enable-privacy-mode' => ['label' => 'Privacy mode'],
        'enable-remote-printer' => ['label' => 'Remote printing', 'help' => 'Windows only. Re-checked per print job, so it takes effect mid-session.'],

        'verification-method' => [
            'label' => 'Password type',
            'choices' => [
                'use-temporary-password' => 'One-time password only',
                'use-permanent-password' => 'Permanent password only',
                'use-both-passwords' => 'Either password',
            ],
        ],
        'approve-mode' => [
            'label' => 'How connections are approved',
            'choices' => [
                'password' => 'Password only',
                'click' => 'Accept on the device only',
                'password-click' => 'Password or accept on the device',
            ],
        ],
        'temporary-password-length' => [
            'label' => 'One-time password length',
            'help' => 'Applies the next time the password is regenerated, not immediately.',
            'choices' => ['6' => '6 characters', '8' => '8 characters', '10' => '10 characters'],
        ],
        'allow-numeric-one-time-password' => [
            'label' => 'Digits-only one-time password',
            'help' => 'Applies at the next regeneration.',
        ],
        'whitelist' => [
            'label' => 'IP whitelist',
            'help' => 'Comma-separated IPs or CIDRs that may connect; empty means allow all. Entries that do not parse simply never match, so a typo here can lock everyone out of the device.',
        ],
        'allow-only-conn-window-open' => ['label' => 'Only accept while the client window is open', 'help' => 'Desktop only.'],
        'enable-trusted-devices' => ['label' => 'Offer "trust this device" on 2FA'],
        // Client wording is "Enable remote configuration modification". Ours
        // said only what it does, which reads better but meant nobody looking
        // for the client's term could find it (#11). Lead with the client's
        // label, keep the plain-English explanation as help.
        'allow-remote-config-modification' => [
            'label' => 'Enable remote configuration modification',
            'help' => 'Lets the connecting side change this device\'s settings during a session.',
        ],
        'allow-auto-update' => [
            'label' => 'Auto update',
            'help' => 'Lets the client update itself. The client only exposes this on installed Windows, and on installed macOS that is not a custom build — elsewhere (Linux, portable installs, custom-branded builds) the toggle is hidden, so setting it here may have no visible effect.',
        ],
        'allow-auto-disconnect' => ['label' => 'Disconnect idle sessions'],
        'auto-disconnect-timeout' => [
            'label' => 'Idle timeout (minutes)',
            'help' => 'Only read when idle disconnect is on. 1–1440.',
        ],
        'allow-scope-violation-alarm' => ['label' => 'Raise an alarm on an out-of-scope message'],
        'allow-scope-violation-close' => ['label' => 'Close the session on an out-of-scope message'],

        'enable-abr' => ['label' => 'Adaptive bitrate'],
        'allow-remove-wallpaper' => ['label' => 'Remove the wallpaper during a session', 'help' => 'Windows and Linux.'],
        'allow-auto-record-incoming' => ['label' => 'Record incoming sessions automatically'],
        'keep-awake-during-incoming-sessions' => ['label' => 'Keep the device awake during a session'],
        'enable-lan-discovery' => ['label' => 'Answer LAN discovery'],
    ];

    /**
     * Every entry point needs at least "View" on strategies, including the
     * Livewire update endpoint. Mutators additionally require "Manage".
     */
    public function boot(): void
    {
        $this->authorizeConsole('strategy', 'r');
    }

    // ------------------------------------------------------------- editor ---

    public function create(): void
    {
        $this->authorizeConsole('strategy', 'rw');

        $this->resetForm();
        $this->editingId = 0;
    }

    public function edit(int $id): void
    {
        $this->authorizeConsole('strategy', 'rw');

        $strategy = Strategy::findOrFail($id);

        $this->resetForm();
        $this->editingId = $strategy->id;
        $this->formName = $strategy->name;
        $this->formNote = (string) $strategy->note;
        $this->formEnabled = (bool) $strategy->enabled;
        $this->formIsDefault = (bool) $strategy->is_default;
        $this->formEnforce = (bool) $strategy->enforce;
        $this->formConfirmationTimeout = (int) ($strategy->confirmation_timeout_minutes ?: 15);

        foreach ($strategy->optionMap() as $key => $value) {
            if (array_key_exists($key, $this->formOptions)) {
                $this->formOptions[$key] = $value;
            }
        }
    }

    /** Existing callers now enter the review flow rather than mutating directly. */
    public function save(): void
    {
        $this->previewSave();
    }

    public function previewSave(): void
    {
        $this->authorizeConsole('strategy', 'rw');

        $this->validate([
            'formName' => [
                'required', 'string', 'max:255',
                Rule::unique('strategies', 'name')->ignore($this->editingId ?: 0),
            ],
            'formNote' => ['nullable', 'string', 'max:500'],
            'formConfirmationTimeout' => ['required', 'integer', 'min:1', 'max:10080'],
            'revisionNote' => ['nullable', 'string', 'max:500'],
        ], [], ['formName' => 'name', 'formNote' => 'note']);

        $options = $this->validatedOptions();
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $this->previewSnapshot = [
            'target_id' => $this->editingId ?: 0,
            'name' => $this->formName,
            'note' => $this->formNote !== '' ? $this->formNote : null,
            'enabled' => $this->formEnabled,
            'is_default' => $this->formIsDefault,
            'enforce' => $this->formEnforce,
            'confirmation_timeout_minutes' => $this->formConfirmationTimeout,
            'options' => $options,
            'revision_note' => $this->revisionNote,
            'operation' => $this->pendingDeleteId !== null ? 'delete' : ($this->restoreRevisionId !== null ? 'restore' : 'save'),
            'restore_revision_id' => $this->restoreRevisionId,
        ];
        $strategy = $this->editingId ? Strategy::findOrFail($this->editingId) : null;
        $this->impactPreview = StrategyImpact::preview($strategy, $this->previewSnapshot);
        $this->previewFingerprint = $this->impactPreview['fingerprint'];
        $this->previewTargetId = $this->editingId ?: 0;
        $this->previewing = true;
    }

    public function confirmSave(): void
    {
        $this->authorizeConsole('strategy', 'rw');

        if (! $this->previewing || $this->previewFingerprint === null || $this->previewTargetId === null) {
            $this->addError('preview', 'Review the strategy impact before applying it.');

            return;
        }

        $snapshot = $this->previewSnapshot;
        $target = $this->previewTargetId > 0 ? Strategy::findOrFail($this->previewTargetId) : null;
        // Recheck immediately before the short write transaction. Do not hold
        // strategy row locks during the fleet scan: on MySQL/MariaDB that would
        // block registrations and approvals behind an operator's confirmation.
        $currentImpact = StrategyImpact::preview($target, $snapshot);
        if (! hash_equals($this->previewFingerprint, $currentImpact['fingerprint'])) {
            $this->addError('preview', 'The strategy or fleet changed after the preview. Review the impact again.');

            return;
        }

        if ($this->pendingDeleteId !== null) {
            if ($target === null || $target->id !== $this->pendingDeleteId) {
                $this->addError('preview', 'The deletion target changed after the preview. Review the impact again.');

                return;
            }
            $strategy = $target;
            $name = $strategy->name;
            $strategy->delete();
            ConsoleAudit::record('strategy.delete', 'Deleted strategy '.$name, 'strategy', $name);
            $this->closeModal();

            return;
        }

        [$strategy, $creating] = DB::transaction(function () use ($snapshot): array {
            // A new default can displace another strategy. Serialize strategy
            // writes and revision allocation in one stable lock order.
            Strategy::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $targetId = (int) ($snapshot['target_id'] ?? 0);
            $strategy = $targetId > 0
                ? Strategy::query()->lockForUpdate()->findOrFail($targetId)
                : new Strategy;
            $displacedDefault = $snapshot['is_default']
                ? Strategy::query()->whereKeyNot($strategy->id ?: 0)->where('is_default', true)->lockForUpdate()->first()
                : null;

            $strategy->fill([
                'name' => $snapshot['name'],
                'note' => $snapshot['note'],
                'enabled' => $snapshot['enabled'],
                'is_default' => $snapshot['is_default'],
                'enforce' => $snapshot['enforce'],
                'confirmation_timeout_minutes' => $snapshot['confirmation_timeout_minutes'],
            ]);
            $strategy->setOptions($snapshot['options']);
            $creating = ! $strategy->exists;
            $strategy->save();

            $revision = StrategyRevision::capture(
                $strategy,
                auth()->id(),
                $snapshot['revision_note'] !== '' ? $snapshot['revision_note'] : ($creating ? 'Initial revision' : 'Saved from the strategy editor'),
            );
            $strategy->forceFill(['active_revision_id' => $revision->id])->saveQuietly();

            if ($displacedDefault !== null) {
                $displacedDefault->refresh();
                $displacedRevision = StrategyRevision::capture(
                    $displacedDefault,
                    auth()->id(),
                    'Default status moved to '.$strategy->name,
                );
                $displacedDefault->forceFill(['active_revision_id' => $displacedRevision->id])->saveQuietly();
            }

            return [$strategy, $creating];
        });

        $restoring = $this->restoreRevisionId !== null;
        ConsoleAudit::record(
            $restoring ? 'strategy.rollback' : ($creating ? 'strategy.create' : 'strategy.update'),
            ($restoring ? 'Restored' : ($creating ? 'Created' : 'Updated')).' strategy '.$strategy->name
                .' ('.count($strategy->optionMap()).' option(s))',
            'strategy',
            $strategy->name,
        );

        $this->closeModal();
    }

    public function closeModal(): void
    {
        $this->editingId = null;
        $this->resetForm();
    }

    public function closePreview(): void
    {
        if ($this->pendingDeleteId !== null) {
            $this->closeModal();

            return;
        }

        $this->reset('previewing', 'impactPreview', 'previewSnapshot', 'previewFingerprint', 'previewTargetId');
        $this->resetValidation('preview');
    }

    public function toggleEnabled(int $id): void
    {
        $this->authorizeConsole('strategy', 'rw');

        $strategy = DB::transaction(function () use ($id): Strategy {
            Strategy::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $strategy = Strategy::query()->lockForUpdate()->findOrFail($id);
            $strategy->update(['enabled' => ! $strategy->enabled]);
            $revision = StrategyRevision::capture($strategy, auth()->id(), 'Toggled enabled state');
            $strategy->forceFill(['active_revision_id' => $revision->id])->saveQuietly();

            return $strategy;
        });

        ConsoleAudit::record(
            'strategy.toggle',
            ($strategy->enabled ? 'Enabled' : 'Disabled').' strategy '.$strategy->name,
            'strategy',
            $strategy->name,
        );
    }

    public function deleteStrategy(int $id): void
    {
        $this->authorizeConsole('strategy', 'rw');

        $this->edit($id);
        $this->pendingDeleteId = $id;
        $this->formEnabled = false;
        $this->formIsDefault = false;
        $this->previewSave();
    }

    // -------------------------------------------------------------- history ---

    public function showHistory(int $strategyId): void
    {
        $this->authorizeConsole('strategy', 'r');
        $strategy = Strategy::findOrFail($strategyId);
        $ids = $strategy->revisions()->orderBy('revision')->pluck('id');
        $this->historyStrategyId = $strategy->id;
        $this->compareFromRevisionId = $ids->count() > 1 ? (int) $ids->first() : null;
        $this->compareToRevisionId = $ids->isNotEmpty() ? (int) $ids->last() : null;
    }

    public function closeHistory(): void
    {
        $this->reset('historyStrategyId', 'compareFromRevisionId', 'compareToRevisionId');
        $this->resetValidation('history');
    }

    public function restoreRevision(int $revisionId): void
    {
        $this->authorizeConsole('strategy', 'rw');

        $revision = StrategyRevision::query()->findOrFail($revisionId);
        $strategy = Strategy::findOrFail($revision->strategy_id);
        $snapshot = is_array($revision->snapshot) ? $revision->snapshot : [];

        $this->closeHistory();
        $this->edit($strategy->id);
        $this->restoreRevisionId = $revision->id;
        $this->revisionNote = 'Restored revision '.$revision->revision;
        // Restoring an old policy never replays routing identity.
        $this->formNote = (string) ($snapshot['note'] ?? '');
        $this->formEnforce = (bool) ($snapshot['enforce'] ?? false);
        $this->formOptions = array_fill_keys(array_keys($this->formOptions), '');
        foreach ((array) ($snapshot['options'] ?? []) as $key => $value) {
            if (array_key_exists($key, $this->formOptions)) {
                $this->formOptions[$key] = (string) $value;
            }
        }
        $this->previewSave();
    }

    public function getHistoryStrategyProperty(): ?Strategy
    {
        return $this->historyStrategyId ? Strategy::find($this->historyStrategyId) : null;
    }

    public function getRevisionHistoryProperty()
    {
        return $this->historyStrategyId
            ? StrategyRevision::query()->where('strategy_id', $this->historyStrategyId)->with('creator')->orderByDesc('revision')->get()
            : collect();
    }

    /** @return array<int,array{key:string,before:mixed,after:mixed}> */
    public function getRevisionComparisonProperty(): array
    {
        if (! $this->historyStrategyId || ! $this->compareFromRevisionId || ! $this->compareToRevisionId) {
            return [];
        }

        $revisions = StrategyRevision::query()->where('strategy_id', $this->historyStrategyId)
            ->whereIn('id', [$this->compareFromRevisionId, $this->compareToRevisionId])->get()->keyBy('id');

        return $revisions->has($this->compareFromRevisionId) && $revisions->has($this->compareToRevisionId)
            ? StrategyRevision::diffSnapshots($revisions[$this->compareFromRevisionId]->snapshot, $revisions[$this->compareToRevisionId]->snapshot)
            : [];
    }

    // ----------------------------------------------------------- compliance ---

    /** Fleet-wide state per device; admins only, like the assignment lists. */
    public function showCompliance(int $strategyId, string $state = 'all'): void
    {
        $this->authorizeConsole('strategy', 'r');
        abort_unless(auth()->user()?->is_admin, 403);
        Strategy::findOrFail($strategyId);
        $this->complianceStrategyId = $strategyId;
        $this->setComplianceState($state);
    }

    public function setComplianceState(string $state): void
    {
        $this->authorizeConsole('strategy', 'r');
        abort_unless(auth()->user()?->is_admin, 403);
        if (in_array($state, ['all', ...StrategyCompliance::STATES], true)) {
            $this->complianceState = $state;
        }
    }

    public function closeCompliance(): void
    {
        $this->reset('complianceStrategyId', 'complianceState');
    }

    // --------------------------------------------------------- assignments ---

    public function openAssign(int $id): void
    {
        $this->authorizeConsole('strategy', 'rw');

        $strategy = Strategy::findOrFail($id);

        $this->assigningId = $strategy->id;
        $this->assignTab = 'devices';
        $this->assignSearch = '';
        $this->assignDeviceIds = $strategy->devices()->pluck('devices.id')->all();
        $this->assignUserIds = $strategy->users()->pluck('users.id')->all();
        $this->assignGroupIds = $strategy->deviceGroups()->pluck('device_groups.id')->all();
    }

    public function setAssignTab(string $tab): void
    {
        if (in_array($tab, ['devices', 'users', 'groups'], true)) {
            $this->assignTab = $tab;
            $this->assignSearch = '';
        }
    }

    public function saveAssign(): void
    {
        $this->authorizeConsole('strategy', 'rw');

        $strategy = Strategy::findOrFail($this->assigningId);

        $this->validate([
            'assignDeviceIds.*' => [Rule::exists('devices', 'id')],
            'assignUserIds.*' => [Rule::exists('users', 'id')],
            'assignGroupIds.*' => [Rule::exists('device_groups', 'id')],
        ]);

        $changed = 0;
        $changed += $this->syncLevel($strategy, Strategy::LEVEL_DEVICE,
            $strategy->devices()->pluck('devices.id')->all(), $this->assignDeviceIds);
        $changed += $this->syncLevel($strategy, Strategy::LEVEL_USER,
            $strategy->users()->pluck('users.id')->all(), $this->assignUserIds);
        $changed += $this->syncLevel($strategy, Strategy::LEVEL_DEVICE_GROUP,
            $strategy->deviceGroups()->pluck('device_groups.id')->all(), $this->assignGroupIds);

        ConsoleAudit::record(
            'strategy.assign',
            'Assignments for strategy '.$strategy->name.': '
                .count($this->assignDeviceIds).' device(s), '
                .count($this->assignUserIds).' user(s), '
                .count($this->assignGroupIds).' device group(s)'
                .' ('.$changed.' change(s))',
            'strategy',
            $strategy->name,
        );

        $this->closeAssign();
    }

    /**
     * Attach the newly checked targets and release the ones this strategy used
     * to hold. Targets assigned to a DIFFERENT strategy are left alone unless
     * they were checked here, in which case assignTo() moves them — one strategy
     * per target is a schema guarantee, so there is no "both" state to reach.
     *
     * @param  array<int,int>  $current
     * @param  array<int,int|string>  $desired
     */
    private function syncLevel(Strategy $strategy, string $level, array $current, array $desired): int
    {
        $current = array_map('intval', $current);
        $desired = array_values(array_unique(array_map('intval', $desired)));

        $changed = 0;

        foreach (array_diff($desired, $current) as $id) {
            Strategy::assignTo($level, $id, $strategy->id);
            $changed++;
        }

        foreach (array_diff($current, $desired) as $id) {
            Strategy::assignTo($level, $id, null);
            $changed++;
        }

        return $changed;
    }

    public function closeAssign(): void
    {
        $this->assigningId = null;
        $this->reset('assignTab', 'assignSearch', 'assignDeviceIds', 'assignUserIds', 'assignGroupIds');
    }

    // -------------------------------------------------------------- render ---

    private function resetForm(): void
    {
        $this->reset('formName', 'formNote', 'formEnabled', 'formIsDefault', 'formEnforce', 'formConfirmationTimeout', 'revisionNote', 'previewing', 'impactPreview', 'previewSnapshot', 'previewFingerprint', 'previewTargetId', 'pendingDeleteId', 'restoreRevisionId');
        $this->resetValidation();
        $this->formOptions = array_fill_keys(array_keys(Strategy::OPTION_KEYS), '');
    }

    /** @return array<string,string> */
    private function validatedOptions(): array
    {
        $options = [];
        foreach ($this->formOptions as $key => $value) {
            $spec = Strategy::OPTION_KEYS[$key] ?? null;
            $value = is_string($value) ? trim($value) : '';

            if ($spec === null || $value === '') {
                continue;
            }

            if ($spec['type'] === 'int' && ! (ctype_digit($value)
                && (int) $value >= ($spec['min'] ?? 0)
                && (int) $value <= ($spec['max'] ?? PHP_INT_MAX))) {
                $this->addError('formOptions.'.$key, 'Enter a whole number between '
                    .($spec['min'] ?? 0).' and '.($spec['max'] ?? PHP_INT_MAX).'.');

                continue;
            }

            $options[$key] = $value;
        }

        return $options;
    }

    /**
     * Option keys, grouped and decorated for the editor. Built from
     * Strategy::OPTION_KEYS, so the editor cannot drift from the allowlist.
     *
     * @return array<string,array{title:string,icon:string,help:string,options:array<string,array<string,mixed>>}>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (Strategy::optionGroups() as $group => $keys) {
            $options = [];

            foreach ($keys as $key => $spec) {
                $meta = self::OPTION_META[$key] ?? [];
                // `allow-*` keys read as a permission, the rest as a switch.
                $on = str_starts_with($key, 'allow-') ? 'Allowed' : 'Enabled';
                $off = str_starts_with($key, 'allow-') ? 'Not allowed' : 'Disabled';

                $choices = match ($spec['type']) {
                    'bool' => ['Y' => $on, 'N' => $off],
                    'enum' => $meta['choices'] ?? [],
                    default => null,
                };

                $options[$key] = [
                    'key' => $key,
                    'type' => $spec['type'],
                    'label' => $meta['label'] ?? $key,
                    'help' => $meta['help'] ?? null,
                    'choices' => $choices,
                ];
            }

            $catalog[$group] = self::GROUP_META[$group] + ['options' => $options];
        }

        return $catalog;
    }

    public function render()
    {
        $strategies = Strategy::query()
            ->withCount(['devices', 'users', 'deviceGroups', 'resolvedDevices'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $isAdmin = auth()->user()?->is_admin === true;
        $complianceStrategy = $isAdmin && $this->complianceStrategyId
            ? $strategies->firstWhere('id', $this->complianceStrategyId)
            : null;
        $complianceSummary = null;
        $complianceDevices = [];
        if ($complianceStrategy !== null) {
            $state = in_array($this->complianceState, ['all', ...StrategyCompliance::STATES], true) ? $this->complianceState : 'all';
            $complianceSummary = app(StrategyCompliance::class)->summary($complianceStrategy, $state);
            $complianceDevices = $state === 'all'
                ? collect($complianceSummary['devices'])->flatten(1)->sortBy('rustdesk_id')->values()->all()
                : $complianceSummary['devices'][$state];
        }

        return view('livewire.strategy-list', [
            'strategies' => $strategies,
            'catalog' => self::catalog(),
            'assigning' => $this->assigningId ? $strategies->firstWhere('id', $this->assigningId) : null,
            'isAdmin' => $isAdmin,
            'complianceStrategy' => $complianceStrategy,
            'complianceSummary' => $complianceSummary,
            'complianceDevices' => $complianceDevices,
            'historyStrategy' => $this->historyStrategy,
            'revisionHistory' => $this->revisionHistory,
            'revisionComparison' => $this->revisionComparison,
        ] + $this->assignCandidates());

        // (assignCandidates() is only non-empty while the assignment modal is open)
    }

    /**
     * Candidates for the assignment modal, plus "already assigned to <other>"
     * hints for the rows on show. Device lists can be large, so that tab is
     * search-driven and capped — the checked set still carries every device the
     * strategy holds, including ones outside the current search.
     *
     * @return array<string,mixed>
     */
    private function assignCandidates(): array
    {
        $empty = ['assignDevices' => collect(), 'assignUsers' => collect(), 'assignGroups' => collect(), 'assignTaken' => []];

        if ($this->assigningId === null) {
            return $empty;
        }

        $devices = Device::query()
            ->when($this->assignSearch !== '' && $this->assignTab === 'devices', function ($q) {
                $s = '%'.$this->assignSearch.'%';
                $q->where(fn ($q) => $q->where('rustdesk_id', 'like', $s)
                    ->orWhere('alias', 'like', $s)
                    ->orWhere('hostname', 'like', $s));
            })
            ->orderBy('rustdesk_id')
            ->limit(200)
            ->get(['id', 'rustdesk_id', 'alias', 'hostname']);

        $users = User::orderBy('username')->get(['id', 'username', 'name']);
        $groups = DeviceGroup::orderBy('name')->get(['id', 'name']);

        return [
            'assignDevices' => $devices,
            'assignUsers' => $users,
            'assignGroups' => $groups,
            'assignTaken' => [
                'devices' => $this->takenBy('device_strategy', 'device_id', $devices->pluck('id')->all()),
                'users' => $this->takenBy('strategy_user', 'user_id', $users->pluck('id')->all()),
                'groups' => $this->takenBy('device_group_strategy', 'device_group_id', $groups->pluck('id')->all()),
            ],
        ];
    }

    /**
     * target id => name of the strategy currently holding it (this one included).
     *
     * @param  array<int,int>  $ids
     * @return array<int,string>
     */
    private function takenBy(string $table, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($table)
            ->join('strategies', 'strategies.id', '=', $table.'.strategy_id')
            ->whereIn($table.'.'.$column, $ids)
            ->pluck('strategies.name', $table.'.'.$column)
            ->all();
    }
}
