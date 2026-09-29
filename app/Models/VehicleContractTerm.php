<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Une durée de contrat véhicule proposée, avec ses montants : le total, et le versement
 * et la taxe journaliers que le contrat FIGE à sa création.
 *
 * Réglée par l'administration. La supprimer ne touche aucun contrat : chacun porte ses
 * propres montants.
 */
class VehicleContractTerm extends Model
{
    use HasUuid;

    protected $fillable = ['months', 'total_amount', 'daily_amount', 'daily_tax'];

    protected $casts = [
        'months' => 'integer',
        'total_amount' => 'decimal:2',
        'daily_amount' => 'decimal:2',
        'daily_tax' => 'decimal:2',
    ];
}
