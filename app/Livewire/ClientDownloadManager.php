<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\ClientDownload;
use App\Models\ConsoleAudit;
use App\Support\ClientPlatform;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Client Downloads — upload the installers rdgen built and publish them on the
 * sign-in page.
 *
 * Permissions: this screen is part of the `setting` area rather than an area of
 * its own. Areas are a fixed matrix (App\Support\Permissions::CONSOLE_RESOURCES,
 * mirrored by Role and the API-token grid), and "who may publish a download the
 * whole internet can fetch" is the same trust level as "who may edit server
 * settings" — so it reuses `setting` instead of widening that matrix.
 *
 * mount() checks View; every mutator re-checks Manage, because /livewire/update
 * is directly reachable (see the AuthorizesConsole docblock).
 */
class ClientDownloadManager extends Component
{
    use AuthorizesConsole;
    use WithFileUploads;

    public bool $showModal = false;

    /** null = uploading a new build, otherwise the row being edited. */
    public ?int $editing = null;

    /**
     * The pending upload. Deliberately untyped: Livewire hydrates it as a
     * TemporaryUploadedFile only while one is staged, and null the rest of the
     * time.
     */
    public $file = null;

    public string $label = '';

    public string $platform = 'unknown';

    public string $arch = '';

    public string $version = '';

    public string $notes = '';

    public bool $isPublished = true;

    /**
     * Set once the operator edits the label or platform by hand, so a later
     * file pick (a "replace file" on an existing row) cannot silently
     * overwrite what they typed.
     */
    public bool $touchedLabel = false;

    public bool $touchedPlatform = false;

    public function mount(): void
    {
        $this->authorizeConsole('setting', 'r');
    }

    public function create(): void
    {
        $this->authorizeConsole('setting', 'rw');

        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeConsole('setting', 'rw');

        $this->resetForm();

        $download = ClientDownload::findOrFail($id);
        $this->editing = $download->id;
        $this->label = $download->label;
        $this->platform = $download->platform;
        $this->arch = $download->arch ?? '';
        $this->version = $download->version ?? '';
        $this->notes = $download->notes ?? '';
        $this->isPublished = $download->is_published;
        // An existing row already has its bytes; the file input is a replace.
        $this->touchedLabel = true;
        $this->touchedPlatform = true;

        $this->showModal = true;
    }

    /**
     * Auto-detect from the filename the moment a file is picked. This is the
     * "detect the platform icon from the file name" half of the feature; the
     * dropdown in the modal is the override, and anything the operator has
     * already typed is left alone.
     */
    public function updatedFile(): void
    {
        $this->authorizeConsole('setting', 'rw');

        if (! $this->file) {
            return;
        }

        $name = $this->file->getClientOriginalName();

        if (! $this->touchedPlatform) {
            $this->platform = ClientPlatform::fromFilename($name);
        }

        if (! $this->touchedLabel) {
            $this->label = ClientPlatform::labelFromFilename($name);
        }

        if ($this->arch === '') {
            $this->arch = ClientPlatform::archFromFilename($name) ?? '';
        }
    }

    public function updatedLabel(): void
    {
        $this->touchedLabel = true;
    }

    public function updatedPlatform(): void
    {
        $this->touchedPlatform = true;
    }

    public function save(): void
    {
        $this->authorizeConsole('setting', 'rw');

        $maxKb = self::maxKilobytes();

        $this->validate([
            // Required only for a new row: editing without picking a file keeps
            // the bytes that are already there.
            'file' => [$this->editing ? 'nullable' : 'required', 'file', 'max:'.$maxKb, function ($attribute, $value, $fail) {
                if ($value && ! ClientPlatform::extensionAllowed($value->getClientOriginalName())) {
                    $fail('That file type is not an installer CortenDesk will hand out. Allowed: '
                        .implode(', ', ClientPlatform::allowedExtensions()).'.');
                }
            }],
            'label' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'in:'.implode(',', ClientPlatform::PLATFORMS)],
            'arch' => ['nullable', 'string', 'max:32'],
            'version' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'file.max' => 'That build is larger than the '.round($maxKb / 1024).' MB upload limit. '
                .'Raise upload_max_filesize/post_max_size in docker/php.ini and client_max_body_size '
                .'in docker/nginx.conf.template together, then CORTENDESK_DOWNLOADS_MAX_KB.',
        ]);

        $download = $this->editing ? ClientDownload::findOrFail($this->editing) : new ClientDownload;

        // Replacing a file: drop the old bytes only once the new ones are
        // safely written, and never before (a failed store would otherwise
        // leave the row pointing at nothing).
        $previous = $download->filename;

        if ($this->file) {
            $original = $this->file->getClientOriginalName();
            $stored = $this->storedName($original);
            $size = $this->file->getSize();

            $this->file->storeAs('', $stored, ['disk' => ClientDownload::DISK]);

            // Hashed from the STORED bytes, not the temporary upload: it is the
            // stored copy an operator will compare against what rdgen built.
            $disk = Storage::disk(ClientDownload::DISK);
            $sha256 = hash_file('sha256', $disk->path($stored)) ?: null;

            $download->fill([
                'filename' => $stored,
                'original_name' => $original,
                'size' => $size,
                'sha256' => $sha256,
            ]);
        }

        $download->fill([
            'label' => trim($this->label),
            'platform' => $this->platform,
            'arch' => trim($this->arch) !== '' ? trim($this->arch) : null,
            'version' => trim($this->version) !== '' ? trim($this->version) : null,
            'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
            'is_published' => $this->isPublished,
        ]);

        if (! $this->editing) {
            $download->uploaded_by = auth()->id();
            $download->sort_order = (int) ClientDownload::max('sort_order') + 1;
        }

        $download->save();

        if ($previous && $previous !== $download->filename) {
            Storage::disk(ClientDownload::DISK)->delete($previous);
        }

        ConsoleAudit::record(
            $this->editing ? 'client_download.update' : 'client_download.create',
            ($this->editing ? 'Updated' : 'Uploaded').' client download '.$download->label
                .' ('.$download->original_name.')',
            'client_download',
            (string) $download->id,
        );

        $this->closeModal();
    }

    public function togglePublished(int $id): void
    {
        $this->authorizeConsole('setting', 'rw');

        $download = ClientDownload::findOrFail($id);
        $download->is_published = ! $download->is_published;
        $download->save();

        ConsoleAudit::record(
            'client_download.'.($download->is_published ? 'publish' : 'unpublish'),
            ($download->is_published ? 'Published' : 'Unpublished').' client download '.$download->label,
            'client_download',
            (string) $download->id,
        );
    }

    public function move(int $id, string $direction): void
    {
        $this->authorizeConsole('setting', 'rw');

        $ordered = ClientDownload::ordered()->get()->values();
        $index = $ordered->search(fn (ClientDownload $d) => $d->id === $id);

        if ($index === false) {
            return;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($target < 0 || $target >= $ordered->count()) {
            return;
        }

        // Rewrite the whole column: sort_order starts life as an append
        // counter, so neighbouring rows can share a value and a bare swap
        // would be a no-op.
        $reordered = $ordered->all();
        [$reordered[$index], $reordered[$target]] = [$reordered[$target], $reordered[$index]];

        foreach ($reordered as $position => $download) {
            $download->update(['sort_order' => $position + 1]);
        }
    }

    public function deleteDownload(int $id): void
    {
        $this->authorizeConsole('setting', 'rw');

        $download = ClientDownload::findOrFail($id);
        $label = $download->label;
        $download->delete(); // the model's deleting hook removes the file

        ConsoleAudit::record(
            'client_download.delete',
            'Deleted client download '.$label,
            'client_download',
            (string) $id,
        );
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'file', 'label', 'arch', 'version', 'notes',
            'touchedLabel', 'touchedPlatform');
        $this->platform = 'unknown';
        $this->isPublished = true;
        $this->resetValidation();
    }

    /**
     * Basename to store the bytes under. Never the uploaded name: it decides a
     * path on disk, and the original is kept in its own column for the
     * Content-Disposition filename.
     */
    private function storedName(string $original): string
    {
        $extension = ClientPlatform::extension($original);
        $base = Str::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'client';

        return Str::limit($base, 80, '').'-'.Str::random(8).($extension ? '.'.$extension : '');
    }

    /**
     * Upload ceiling in KB. Kept below PHP's post_max_size on purpose: past it
     * the request is dropped before Laravel sees it, and the operator gets an
     * empty page instead of a validation message.
     */
    public static function maxKilobytes(): int
    {
        return max(1024, (int) config('cortendesk.downloads_max_kb', 30720));
    }

    public function render()
    {
        return view('livewire.client-download-manager', [
            'downloads' => ClientDownload::ordered()->with('uploader')->get(),
            'platformOptions' => ClientPlatform::options(),
            'maxKb' => self::maxKilobytes(),
            'canManage' => $this->consoleAllows('setting', 'rw'),
        ]);
    }
}
