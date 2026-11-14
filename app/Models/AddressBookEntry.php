<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['address_book_id', 'rustdesk_id', 'alias', 'note', 'hostname', 'platform', 'username', 'password_enc', 'hash', 'login_name', 'force_always_relay', 'rdp_port', 'rdp_username', 'tag_ids'])]
class AddressBookEntry extends Model
{
    /** The client's note field has no limit; ours keeps a sync from failing on a huge one. */
    public const NOTE_MAX = 2000;

    protected function casts(): array
    {
        return [
            'force_always_relay' => 'boolean',
            'tag_ids' => 'array',
        ];
    }

    /** A note as stored: trimmed, cut to NOTE_MAX, null when empty. */
    public static function cleanNote(mixed $note): ?string
    {
        $note = mb_substr(trim((string) $note), 0, self::NOTE_MAX);

        return $note === '' ? null : $note;
    }

    public function addressBook(): BelongsTo
    {
        return $this->belongsTo(AddressBook::class);
    }

    /** See Device::canBeControlled(). Clients sync the platform as "iOS". */
    public function canBeControlled(): bool
    {
        return strtolower(trim((string) $this->platform)) !== 'ios';
    }
}
