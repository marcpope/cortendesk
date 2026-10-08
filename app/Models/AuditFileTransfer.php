<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['rustdesk_id', 'from_peer', 'from_name', 'path', 'info', 'is_file', 'direction', 'file_count', 'ip', 'uuid'])]
class AuditFileTransfer extends Model
{
    /**
     * The files the client listed in `info.files`: up to the 10 largest, as
     * [name, size] pairs (docs/client-api.md §21). A single-file job lists
     * one entry with an empty name, which is dropped here; its path is the
     * file.
     *
     * @return array<int, array{name: string, size: int}>
     */
    public function transferredFiles(): array
    {
        $info = json_decode((string) $this->info, true);
        $files = is_array($info) && is_array($info['files'] ?? null) ? $info['files'] : [];

        $out = [];
        foreach ($files as $file) {
            $name = is_array($file) ? ($file[0] ?? '') : '';
            if (is_string($name) && $name !== '') {
                $out[] = ['name' => $name, 'size' => (int) ($file[1] ?? 0)];
            }
        }

        return $out;
    }

    /** @return array<int, string> */
    public function fileNames(): array
    {
        return array_column($this->transferredFiles(), 'name');
    }

    /**
     * Clipboard copy-paste of files. The client posts those with an empty
     * path by design and names the files in `info.files` instead.
     */
    public function isClipboard(): bool
    {
        return (string) $this->path === '' && $this->fileNames() !== [];
    }

    /**
     * What to show in the Path column: the path when there is one, else the
     * file names, e.g. "report.pdf, notes.txt +3 more".
     */
    public function pathLabel(int $show = 2): string
    {
        if ((string) $this->path !== '') {
            return (string) $this->path;
        }

        $names = $this->fileNames();
        if ($names === []) {
            return '';
        }

        $total = max((int) $this->file_count, count($names));
        $label = implode(', ', array_slice($names, 0, $show));
        $more = $total - min($show, count($names));

        return $more > 0 ? $label.' +'.$more.' more' : $label;
    }

    protected function casts(): array
    {
        return [
            'is_file' => 'boolean',
        ];
    }
}
