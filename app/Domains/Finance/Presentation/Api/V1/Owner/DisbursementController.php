<?php

namespace App\Domains\Finance\Presentation\Api\V1\Owner;

use App\Domains\Finance\Application\Actions\BuildDisbursementSummary;
use App\Domains\Finance\Application\Data\DisbursementSummaryData;
use App\Domains\Finance\Application\Data\OwnerDisbursementData;
use App\Domains\Finance\Application\Data\OwnerDisbursementsData;
use App\Models\OwnerDisbursement;
use App\Models\RemunerationStatement;
use App\Models\Vehicle;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les versements, côté propriétaire (spec 2026-10-22-versement-proprietaire, §5.2). La portée
 * passe par le VÉHICULE du propriétaire, puis par SES contrats sur ce véhicule : un véhicule
 * qui a changé de mains ne montre pas l'argent de l'ancien propriétaire. Hors portée : 404.
 */
final class DisbursementController
{
    public function index(Request $request, string $id, BuildDisbursementSummary $summary): JsonResponse
    {
        try {
            $vehicle = Vehicle::query()->where('owner_id', $request->user()->id)->findOrFail($id);
            if (! RemunerationStatement::visibleToOwners()) {
                return response()->json(new OwnerDisbursementsData(summary: null, disbursements: null));
            }

            $contractIds = $vehicle->vehicleContracts()->where('owner_id', $request->user()->id)->pluck('id');
            $figures = $summary($contractIds);

            return response()->json(new OwnerDisbursementsData(
                // Un dû négatif (fiche annulée après des versements) se lit 0 chez le propriétaire (§4.1).
                summary: new DisbursementSummaryData($figures->netEarned, $figures->disbursed, max(0.0, $figures->outstanding)),
                disbursements: OwnerDisbursement::query()
                    ->whereIn('vehicle_contract_id', $contractIds)->where('status', 'recorded')
                    ->orderByDesc('paid_on')->orderByDesc('created_at')->get()
                    ->map(fn ($d) => OwnerDisbursementData::fromModel($d))->all(),
            ));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la lecture des versements', 'OWNER_DISBURSEMENTS_READ_FAILED');
        }
    }

    public function proof(Request $request, string $id): Response
    {
        try {
            $owner = $request->user()->id;
            $disbursement = RemunerationStatement::visibleToOwners() ? OwnerDisbursement::query()
                ->where('status', 'recorded')->whereNotNull('proof_path')
                ->whereHas('contract', fn (Builder $q) => $q->where('owner_id', $owner)
                    ->whereHas('vehicle', fn (Builder $v) => $v->where('owner_id', $owner)))
                ->find($id) : null;
            if (! $disbursement || ! Storage::disk('local')->exists($disbursement->proof_path)) {
                throw (new ModelNotFoundException)->setModel(OwnerDisbursement::class);
            }

            return Storage::disk('local')->download($disbursement->proof_path, $disbursement->proof_original_name ?? 'preuve');
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la preuve d\'un versement', 'OWNER_DISBURSEMENT_PROOF_FAILED');
        }
    }

    private function failure(\Throwable $e, Request $request, string $what, string $code): JsonResponse
    {
        Log::error("Erreur lors de {$what} : ".$e->getMessage(), ['exception' => $e, 'user_id' => $request->user()?->id]);

        return response()->json(['message' => 'Les versements n\'ont pas pu être chargés. Réessayez.', 'code' => $code], 500);
    }
}
