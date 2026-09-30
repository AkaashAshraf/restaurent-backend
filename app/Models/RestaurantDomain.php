<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class RestaurantDomain extends Model
{
    use BelongsToTenant;

    protected $fillable = ['restaurant_id', 'domain', 'type', 'is_primary', 'status'];

    protected $casts = ['is_primary' => 'boolean'];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
