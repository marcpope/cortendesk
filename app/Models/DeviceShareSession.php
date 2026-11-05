<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser that opened a share link. The share page pings while it is
 * open; a session that stops pinging no longer counts as a viewer.
 */
class DeviceShareSession extends Model
{
    /** The share page pings this often (seconds). */
    public const PING_SECONDS = 30;

    /** A session is live while its last ping is younger than this. */
    public const LIVE_SECONDS = 90;

    public $timestamps = false;

    protected $fillable = [
        'device_share_id', 'key_hash', 'ip', 'user_agent', 'started_at', 'last_seen_at', 'ended_at',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function share(): BelongsTo
    {
        return $this->belongsTo(DeviceShare::class, 'device_share_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('last_seen_at', '>', now()->subSeconds(self::LIVE_SECONDS));
    }
}
