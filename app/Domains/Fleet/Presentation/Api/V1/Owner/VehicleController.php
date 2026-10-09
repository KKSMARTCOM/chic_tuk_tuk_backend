<?php

namespace App\Domains\Fleet\Presentation\Api\V1\Owner;

use App\Domains\Finance\Application\Actions\BuildMonthlyPayoutRecap;
use App\Domains\Finance\Application\Actions\ListOwnerVehiclePayments;
use App\Domains\Fleet\Application\Actions\ListOwnerVehicles;
use App\Domains\Fleet\Application\Data\OwnerVehicleDetailData;
use App\Domains\Fleet\Application\Data\OwnerVehiclePausesData;
use App\Models\Vehicle;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les lectures de l'espace propriétaire.
 *
 * Mêmes règles de try/catch que l'AuthController de l'API : ValidationException et
 * ApiException relancées EN PREMIER — elles sont la réponse voulue et le renderer sait
 * déjà les rendre ; le message d'exception va au JOURNAL et jamais dans la réponse, où
 * il emporterait régulièrement un fragment SQL ; et on attrape \Throwable et non
 * \Exception, car les \Error (TypeError, ValueError) n'héritent pas d'Exception.
 */
final class VehicleController
{
    public function index(Request $request, ListOwnerVehicles $list): JsonResponse
    {
        try {
            return response()->json($list($request->user()->id));
        } catch (ValidationException|ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la lecture des véhicules du propriétaire : '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Vos véhicules n\'ont pas pu être chargés. Réessayez.',
                'code' => 'OWNER_VEHICLES_READ_FAILED',
            ], 500);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $vehicle = $this->owned($request, $id);
            $vehicle->load(['activeVehicleContract', 'liveVehicleContract', 'activePause']);

            return response()->json(OwnerVehicleDetailData::fromModel($vehicle));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            // ⚠️ ModelNotFoundException est relancée AVEC les deux autres : attrapée par
            // le \Throwable plus bas, le 404 de portée deviendrait un 500, et le
            // véhicule d'autrui cesserait d'être introuvable pour paraître en panne.
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la lecture d\'un véhicule du propriétaire : '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
                'vehicle_id' => $id,
            ]);

            return response()->json([
                'message' => 'Ce véhicule n\'a pas pu être chargé. Réessayez.',
                'code' => 'OWNER_VEHICLE_READ_FAILED',
            ], 500);
        }
    }

    public function pauses(Request $request, string $id): JsonResponse
    {
        try {
            $vehicle = $this->owned($request, $id);
            $vehicle->load(['pauses', 'driverContracts.leaveRequests', 'activeVehicleContract']);

            return response()->json(OwnerVehiclePausesData::fromModel($vehicle));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la lecture des pauses d\'un véhicule : '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
                'vehicle_id' => $id,
            ]);

            return response()->json([
                'message' => 'Les pauses de ce véhicule n\'ont pas pu être chargées. Réessayez.',
                'code' => 'OWNER_PAUSES_READ_FAILED',
            ], 500);
        }
    }

    public function payments(Request $request, string $id, BuildMonthlyPayoutRecap $recap): JsonResponse
    {
        try {
            $vehicle = $this->owned($request, $id);
            $contract = $vehicle->activeVehicleContract;

            // Pas de contrat actif : pas de récapitulatif. Un tableau vide, pas un 404 —
            // le véhicule existe, c'est son contrat qui manque.
            return response()->json($contract ? $recap($contract) : []);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors du calcul du récapitulatif mensuel : '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
                'vehicle_id' => $id,
            ]);

            return response()->json([
                'message' => 'Le récapitulatif de ce véhicule n\'a pas pu être chargé. Réessayez.',
                'code' => 'OWNER_PAYMENTS_READ_FAILED',
            ], 500);
        }
    }

    /** Les paiements du véhicule un par un, filtrés et paginés (2026-10-07). */
    public function paymentList(Request $request, string $id, ListOwnerVehiclePayments $list): JsonResponse
    {
        try {
            $vehicle = $this->owned($request, $id);

            return response()->json($list($vehicle, $request->user()->id, $request->query()));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la lecture des paiements d\'un véhicule : '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
                'vehicle_id' => $id,
            ]);

            return response()->json([
                'message' => 'Les paiements de ce véhicule n\'ont pas pu être chargés. Réessayez.',
                'code' => 'OWNER_PAYMENT_LIST_READ_FAILED',
            ], 500);
        }
    }

    /**
     * Le véhicule visé, à condition qu'il appartienne à l'appelant.
     *
     * ⚠️ Unique endroit où la règle de propriété est écrite. Toute action prenant un
     * identifiant de véhicule DOIT passer par ici.
     *
     * `findOrFail` produit une ModelNotFoundException, rendue en 404 NOT_FOUND. C'est
     * délibérément un 404 et non le 403 du chemin Blade, dont le message
     * « Ce véhicule ne vous appartient pas » confirme au passant que le véhicule existe.
     */
    private function owned(Request $request, string $id): Vehicle
    {
        return Vehicle::query()
            ->where('owner_id', $request->user()->id)
            ->findOrFail($id);
    }
}
