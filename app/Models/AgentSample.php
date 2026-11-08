<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agent_id', 'taken_at', 'cpu_percent', 'mem_used', 'mem_total', 'swap_used', 'disk_percent', 'rx_bps', 'tx_bps', 'load1'])]
class AgentSample extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'cpu_percent' => 'float',
            'disk_percent' => 'float',
            'load1' => 'float',
            'mem_used' => 'integer',
            'mem_total' => 'integer',
            'swap_used' => 'integer',
            'rx_bps' => 'integer',
            'tx_bps' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
