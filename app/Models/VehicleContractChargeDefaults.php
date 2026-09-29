<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Les trois charges mensuelles qui préremplissent un contrat véhicule. Une seule ligne,
 * amorcée par la migration ; un contrat en garde une copie à sa création.
 */
class VehicleContractChargeDefaults extends Model
{
    use HasUuid;

    protected $table = 'vehicle_contract_charge_defaults';

    protected $fillable = ['unlimited_internet', 'spotify_premium', 'manager_remuneration'];

    protected $casts = [
        'unlimited_internet' => 'decimal:2',
        'spotify_premium' => 'decimal:2',
        'manager_remuneration' => 'decimal:2',
    ];
}
