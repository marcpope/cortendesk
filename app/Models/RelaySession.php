<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A session CortenDesk Server's relay (hbbr) carried, as it reported it
 * (issue #130). conn_type is already the console's session type.
 */
#[Fillable(['uuid', 'rustdesk_id', 'conn_type', 'controller_ip', 'device_ip', 'started_at', 'ended_at', 'bytes'])]
class RelaySession extends Model
{
    protected function casts(): array
    {
        return [
            'conn_type' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'bytes' => 'integer',
        ];
    }
}
