<?php

namespace App\Domains\Content\Presentation\Api\V1\Owner;

use App\Domains\Content\Application\Data\OwnerGlossaryTermData;
use App\Models\GlossaryTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Le glossaire de l'espace propriétaire, en lecture : les seuls termes visibles, par ordre alphabétique. */
final class GlossaryController
{
    public function index(Request $request): JsonResponse
    {
        try {
            return response()->json(GlossaryTerm::where('is_visible', true)->orderBy('term_key')->get()
                ->map(fn (GlossaryTerm $t) => OwnerGlossaryTermData::fromModel($t))->all());
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la lecture du glossaire : '.$e->getMessage(), ['exception' => $e, 'user_id' => $request->user()?->id]);

            return response()->json(['message' => 'Le glossaire n\'a pas pu être chargé. Réessayez.', 'code' => 'OWNER_GLOSSARY_READ_FAILED'], 500);
        }
    }
}
