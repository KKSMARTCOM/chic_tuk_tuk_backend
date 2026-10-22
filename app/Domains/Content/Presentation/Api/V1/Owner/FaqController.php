<?php

namespace App\Domains\Content\Presentation\Api\V1\Owner;

use App\Domains\Content\Application\Actions\ShowOwnerFaq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** La FAQ de l'espace propriétaire (spec 2026-10-22-faq-proprietaire, §4.2), en lecture. */
final class FaqController
{
    public function index(Request $request, ShowOwnerFaq $show): JsonResponse
    {
        try {
            return response()->json($show());
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la lecture de la FAQ : '.$e->getMessage(), ['exception' => $e, 'user_id' => $request->user()?->id]);

            return response()->json(['message' => 'La FAQ n\'a pas pu être chargée. Réessayez.', 'code' => 'OWNER_FAQ_READ_FAILED'], 500);
        }
    }
}
