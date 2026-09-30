<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\TableStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Named `Table` (spec calls this table management); Laravel's own facade
 * is `Illuminate\Support\Facades\Schema`/`DB`, not `Table`, so there's no
 * clash, but the DB table itself is named `tables` — see the migration.
 */
class Table extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $table = 'tables';

    protected $fillable = [
        'restaurant_id', 'branch_id', 'table_number', 'capacity', 'section', 'status',
    ];

    protected $casts = [
        'status' => TableStatus::class,
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The one order (if any) currently occupying this table — i.e. not
     * COMPLETED/CANCELLED. Relies on OrderStatus::occupiesTable() (the same
     * source of truth OrderService::updateStatus() uses to decide whether
     * to release the table) rather than re-deriving "active" here.
     */
    public function activeOrder(): HasOne
    {
        $activeStatuses = array_map(
            fn (OrderStatus $s) => $s->value,
            array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->occupiesTable())
        );

        return $this->hasOne(Order::class)->whereIn('status', $activeStatuses)->latest();
    }
}
