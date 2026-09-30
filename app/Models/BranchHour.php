<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchHour extends Model
{
    protected $fillable = [
        'branch_id', 'restaurant_id', 'day_of_week', 'open_time', 'close_time',
        'is_closed', 'is_24_hours',
    ];

    protected $casts = [
        'is_closed' => 'boolean',
        'is_24_hours' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
