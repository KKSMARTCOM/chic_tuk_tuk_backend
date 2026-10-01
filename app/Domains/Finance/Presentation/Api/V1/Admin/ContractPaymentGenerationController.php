<?php

namespace App\Domains\Finance\Presentation\Api\V1\Admin;

use App\Domains\Finance\Application\Actions\GenerateDriverContractPayments;
use App\Domains\Finance\Application\Actions\PlanDriverContractPayments;
use App\Domains\Finance\Application\Data\ContractPaymentPeriodData;
use App\Domains\Finance\Application\Data\ContractPaymentPreviewData;
use App\Domains\Finance\Application\Data\GenerateContractPaymentsData;
use App\Models\DriverContract;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Générer les paiements d'une période pour un contrat agent, en cours ou terminé (spec 2026-10-01, §3). */
final class ContractPaymentGenerationController
{
    public function preview(Request $request, string $id, ContractPaymentPeriodData $data, PlanDriverContractPayments $plan): JsonResponse
    {
        try {
            $contract = DriverContract::with('vehicleContract')->findOrFail($id);

            return response()->json(ContractPaymentPreviewData::fromPlan($contract, $plan($contract, Carbon::parse($data->from), Carbon::parse($data->to))));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'l\'aperçu de la génération', 'L\'aperçu n\'a pas pu être calculé. Réessayez.', 'CONTRACT_PAYMENTS_PREVIEW_FAILED');
        }
    }

    public function generate(Request $request, string $id, GenerateContractPaymentsData $data, GenerateDriverContractPayments $generate): JsonResponse
    {
        try {
            $from = Carbon::parse($data->from);
            $to = Carbon::parse($data->to);
            $note = 'Paiement généré — période du '.$from->format('d/m/Y').' au '.$to->format('d/m/Y');

            return response()->json($generate(DriverContract::findOrFail($id), $from, $to, $data->regenerateCancelled, $note, $data->expected));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la génération des paiements', 'Les paiements n\'ont pas pu être générés. Réessayez.', 'CONTRACT_PAYMENTS_GENERATE_FAILED');
        }
    }

    private function failure(\Throwable $e, Request $request, string $what, string $message, string $code): JsonResponse
    {
        Log::error("Erreur lors de {$what} : ".$e->getMessage(), ['exception' => $e, 'user_id' => $request->user()?->id]);

        return response()->json(['message' => $message, 'code' => $code], 500);
    }
}
