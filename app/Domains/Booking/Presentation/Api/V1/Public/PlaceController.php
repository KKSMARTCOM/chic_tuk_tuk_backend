<?php

namespace App\Domains\Booking\Presentation\Api\V1\Public;

use App\Domains\Booking\Application\Actions\SearchPlaces;
use App\Domains\Booking\Application\Data\PlaceSearchData;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class PlaceController extends Controller
{
    private const MIN_LENGTH = 2;

    private const MAX_LENGTH = 100;

    /**
     * GET /api/v1/public/places?q=…
     *
     * Sous 2 caractères, une liste vide sans rien interroger (spec
     * 2026-10-27-recherche-de-lieux, §4.1). `SearchPlaces` ne lève pas sur la panne d'un
     * moteur ; le `catch` ne couvre que l'imprévu.
     */
    public function index(Request $request, SearchPlaces $search): JsonResponse
    {
        // Un `q[]=…` (robots) se lit comme une saisie vide, sans erreur au journal.
        $raw = $request->query('q');
        $query = is_string($raw) ? trim($raw) : '';

        if (mb_strlen($query) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['q' => 'La recherche ne dépasse pas 100 caractères.']);
        }
        if (mb_strlen($query) < self::MIN_LENGTH) {
            return response()->json(new PlaceSearchData([]));
        }

        try {
            return response()->json($search($query));
        } catch (\Throwable $e) {
            Log::error('Recherche de lieux indisponible : '.$e->getMessage(), ['exception' => $e]);

            return response()->json(['message' => 'La recherche de lieux est momentanément indisponible.', 'code' => 'PLACE_SEARCH_FAILED'], 500);
        }
    }
}
