<?php

namespace App\Models;

use App\Support\ClientPlatform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A custom RustDesk client build offered for download.
 *
 * The bytes live on the private `downloads` disk and are only ever streamed by
 * ClientDownloadController, so nothing here — and nothing in a view — should
 * build a URL from `filename`.
 */
#[Fillable([
    'label', 'platform', 'arch', 'version', 'notes',
    'filename', 'original_name', 'size', 'sha256',
    'is_published', 'sort_order', 'uploaded_by',
])]
class ClientDownload extends Model
{
    /** The disk the uploaded bytes live on (rooted in /data in Docker). */
    public const DISK = 'downloads';

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'size' => 'integer',
            'sort_order' => 'integer',
            'download_count' => 'integer',
        ];
    }

    /**
     * Deleting a row deletes its bytes. Registered as a model event rather
     * than done in the component so a future console command or tinker session
     * cannot leave orphaned files behind in the volume.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $download) {
            Storage::disk(self::DISK)->delete($download->filename);
        });
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Rows the public download page may show. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Operator order first, then platform, then newest — so an install that
     * never touches sort_order still gets a stable, sensible list.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('platform')->orderByDesc('id');
    }

    public function platformLabel(): string
    {
        return ClientPlatform::label($this->platform);
    }

    public function archLabel(): string
    {
        return ClientPlatform::archLabel($this->arch);
    }

    /** "24.6 MB" — the size the download link advertises. */
    public function humanSize(): string
    {
        $bytes = (int) $this->size;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;

        foreach ($units as $i => $unit) {
            if ($value < 1024 || $i === count($units) - 1) {
                return round($value, $value < 10 ? 1 : 0).' '.$unit;
            }

            $value /= 1024;
        }

        return $bytes.' B';
    }

    /** Whether the bytes are actually still on disk (a recreated volume is not). */
    public function fileExists(): bool
    {
        return Storage::disk(self::DISK)->exists($this->filename);
    }
}
