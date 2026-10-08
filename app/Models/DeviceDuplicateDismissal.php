<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Two devices an operator confirmed are separate machines, so the duplicate
 * check stops pairing them (issue #91). Stored with the lower id first.
 */
#[Fillable(['device_id', 'other_device_id', 'user_id'])]
class DeviceDuplicateDismissal extends Model
{
    /** Record the pair once, whichever order the ids arrive in. */
    public static function dismiss(int $a, int $b, ?int $userId): self
    {
        return static::firstOrCreate(
            ['device_id' => min($a, $b), 'other_device_id' => max($a, $b)],
            ['user_id' => $userId],
        );
    }
}
