<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Une étape de la liste par défaut, proposée à tout nouveau contrat en attente (spec 2026-10-20, §3.1). */
class PreparationStepTemplate extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = ['label', 'position'];

    protected $casts = ['position' => 'integer'];
}
