<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for outbound Apprise attempts. Transport configuration is held
 * separately in encrypted settings and is deliberately absent from this table.
 *
 * One row per notification. A retry updates the row it retries: attempts
 * counts sends, error holds the latest failure, and next_retry_at is set only
 * while a retry is pending. body is kept for the retry and cleared once the
 * row is final.
 */
#[Fillable(['event', 'subject', 'status', 'attempts', 'next_retry_at', 'title', 'body', 'error'])]
#[Hidden(['body'])]
class NotificationDelivery extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPPRESSED = 'suppressed';

    /** A pending retry dropped because a newer event made it stale. */
    public const STATUS_SUPERSEDED = 'superseded';

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_retry_at' => 'datetime',
        ];
    }

    public function retryPending(): bool
    {
        return $this->status === self::STATUS_FAILED && $this->next_retry_at !== null;
    }
}
