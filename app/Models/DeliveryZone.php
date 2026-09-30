<?php

namespace App\Models;

use App\Enums\DeliveryZoneType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delivery zone is one branch's own definition of "we deliver here."
 * Two independent shapes, chosen by `type`:
 *   - RADIUS: a circle — center_latitude/center_longitude + radius_km.
 *   - POLYGON: an arbitrary shape — a `polygon` array of {lat, lng}
 *     points, at least 3, checked with a standard ray-casting test.
 *
 * `containsPoint()` is the one place either shape's math lives, so
 * GeofencingService (and anything else that needs "is this point
 * deliverable") never has to know which shape it's dealing with.
 */
class DeliveryZone extends Model
{
    use HasFactory, BelongsToTenant;

    private const EARTH_RADIUS_KM = 6371.0;

    protected $fillable = [
        'restaurant_id', 'branch_id', 'name', 'type',
        'center_latitude', 'center_longitude', 'radius_km', 'polygon',
        'delivery_fee_override', 'is_active',
    ];

    protected $casts = [
        'type' => DeliveryZoneType::class,
        'center_latitude' => 'float',
        'center_longitude' => 'float',
        'radius_km' => 'float',
        'polygon' => 'array',
        'delivery_fee_override' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function containsPoint(float $lat, float $lng): bool
    {
        return match ($this->type) {
            DeliveryZoneType::RADIUS => $this->withinRadius($lat, $lng),
            DeliveryZoneType::POLYGON => $this->withinPolygon($lat, $lng),
        };
    }

    private function withinRadius(float $lat, float $lng): bool
    {
        if ($this->center_latitude === null || $this->center_longitude === null || $this->radius_km === null) {
            return false;
        }

        return $this->haversineKm($this->center_latitude, $this->center_longitude, $lat, $lng) <= $this->radius_km;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * Standard ray-casting point-in-polygon test: count how many times a
     * ray cast from the point crosses the polygon's edges. Odd = inside.
     */
    private function withinPolygon(float $lat, float $lng): bool
    {
        $points = $this->polygon ?? [];
        $count = count($points);

        if ($count < 3) {
            return false;
        }

        $inside = false;
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $latI = (float) $points[$i]['lat'];
            $lngI = (float) $points[$i]['lng'];
            $latJ = (float) $points[$j]['lat'];
            $lngJ = (float) $points[$j]['lng'];

            $crossesRay = ($lngI > $lng) !== ($lngJ > $lng);
            if ($crossesRay) {
                $intersectionLat = ($latJ - $latI) * ($lng - $lngI) / ($lngJ - $lngI) + $latI;
                if ($lat < $intersectionLat) {
                    $inside = ! $inside;
                }
            }
        }

        return $inside;
    }
}
