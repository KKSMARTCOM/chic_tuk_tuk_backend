<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Zone extends Model
{
    use HasUuid, Notifiable, HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'is_active'
    ];

    // `pricesFrom()` et `pricesTo()` retirées le 2026-09-27 avec la section « Tarifs » :
    // aucun code ne les appelait, et le modèle `Pricing` n'existe plus.
}
