<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['address_book_id', 'rustdesk_id', 'alias', 'hostname', 'platform', 'username', 'password_enc', 'hash', 'login_name', 'force_always_relay', 'rdp_port', 'rdp_username', 'tag_ids'])]
class AddressBookEntry extends Model
{
    protected function casts(): array
    {
        return [
            'force_always_relay' => 'boolean',
            'tag_ids' => 'array',
        ];
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
