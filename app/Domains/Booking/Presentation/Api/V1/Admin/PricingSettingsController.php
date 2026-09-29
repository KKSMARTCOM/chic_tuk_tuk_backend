<?php

namespace App\Domains\Booking\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Booking\Application\Actions\UpdatePricingSettings;
use App\Domains\Booking\Application\Data\PricingSettingsData;
use App\Models\PricingSettings;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Le prix des courses, réglé par l'administration. Mêmes règles de try/catch que le
 * reste de l'API v1.
 */
final class PricingSettingsController
{
    public function show(Request $request): JsonResponse
    {
        try {
            return response()->json(PricingSettingsData::fromModel(PricingSettings::current()));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la lecture des tarifs',
                'Les tarifs n\'ont pas pu être chargés. Réessayez.', 'PRICING_SETTINGS_READ_FAILED');
        }
    }

    public function update(Request $request, PricingSettingsData $data, UpdatePricingSettings $update, ActivityJournal $journal): JsonResponse
    {
        try {
            $before = PricingSettingsData::fromModel(PricingSettings::current())->toArray();
            $after = $update($data);
            $journal->pricingUpdated($before, $after->toArray());

            return response()->json($after);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'l\'enregistrement des tarifs',
                'Les tarifs n\'ont pas pu être enregistrés. Réessayez.', 'PRICING_SETTINGS_UPDATE_FAILED');
        }
    }

    private function failure(\Throwable $e, Request $request, string $what, string $message, string $code): JsonResponse
    {
        Log::error("Erreur lors de {$what} : ".$e->getMessage(), [
            'exception' => $e,
            'user_id' => $request->user()?->id,
        ]);

        return response()->json(['message' => $message, 'code' => $code], 500);
    }
}
