<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Le prix des courses, réglé par l'administration : prix de base (aussi le minimum d'une
 * course), prix au kilomètre, et majoration horaire appliquée hors de la plage
 * `surcharge_free_start_hour`–`surcharge_free_end_hour` (bornes incluses). Une seule
 * ligne, amorcée par la migration.
 */
class PricingSettings extends Model
{
    use HasUuid;

    protected $table = 'pricing_settings';

    protected $fillable = [
        'base_price',
        'price_per_km',
        'time_surcharge',
        'surcharge_free_start_hour',
        'surcharge_free_end_hour',
    ];

    protected $casts = [
        'base_price' => 'integer',
        'price_per_km' => 'integer',
        'time_surcharge' => 'integer',
        'surcharge_free_start_hour' => 'integer',
        'surcharge_free_end_hour' => 'integer',
    ];

    /** « 6h–10h » : la plage sans majoration, telle que le devis l'annonce. */
    public function surchargeFreeWindow(): string
    {
        return $this->surcharge_free_start_hour.'h–'.$this->surcharge_free_end_hour.'h';
    }

    public static function current(): self
    {
        return self::query()->firstOrFail();
    }
}
