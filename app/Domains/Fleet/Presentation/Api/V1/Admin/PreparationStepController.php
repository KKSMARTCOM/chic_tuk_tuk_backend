<?php

namespace App\Domains\Fleet\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Fleet\Application\Actions\AddPreparationStep;
use App\Domains\Fleet\Application\Actions\AttachPreparationProof;
use App\Domains\Fleet\Application\Actions\RemovePreparationProof;
use App\Domains\Fleet\Application\Actions\RemovePreparationStep;
use App\Domains\Fleet\Application\Actions\UpdatePreparationStep;
use App\Domains\Fleet\Application\Data\AdminPreparationData;
use App\Domains\Notification\Application\Notifier;
use App\Models\VehicleContract;
use App\Models\VehicleContractPreparationStep;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les gestes de l'admin sur les étapes de préparation d'un contrat (spec 2026-10-20, §5.2).
 * Le contrôleur trace et notifie, d'après ce que rendent les actions.
 */
final class PreparationStepController
{
    public function __construct(
        private readonly ActivityJournal $journal,
        private readonly Notifier $notifier,
    ) {}

    public function store(Request $request, string $contractId, AddPreparationStep $add): JsonResponse
    {
        return $this->guard($request, 'l\'ajout d\'une étape de préparation', function () use ($request, $contractId, $add) {
            $contract = VehicleContract::findOrFail($contractId);
            $data = Validator::make($request->all(), ['label' => ['required', 'string', 'max:120']], self::MESSAGES)->validate();
            $step = $add($contract, $data['label']);
            $this->journal->preparationStepAdded($step);

            return AdminPreparationData::forContract($contract);
        });
    }

    public function update(Request $request, string $contractId, string $stepId, UpdatePreparationStep $update): JsonResponse
    {
        return $this->guard($request, 'la modification d\'une étape de préparation', function () use ($request, $contractId, $stepId, $update) {
            [$contract, $step] = $this->resolve($contractId, $stepId);
            $data = Validator::make($request->all(), [
                'label' => ['sometimes', 'required', 'string', 'max:120'],
                'done_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            ], self::MESSAGES)->validate();
            $changes = $update($contract, $step, $data, $request->user());

            $step->refresh()->load('vehicleContract.vehicle.owner');
            if ($changes['renamed'] !== null) {
                $this->journal->preparationStepRenamed($step, $changes['renamed']);
            }
            if ($changes['done']) {
                $this->journal->preparationStepDone($step);
                $this->notifier->preparationStepDone($step);
            }
            if ($changes['redated']) {
                $this->journal->preparationStepRedated($step);
            }
            if ($changes['undone']) {
                $this->journal->preparationStepUndone($step);
            }

            return AdminPreparationData::forContract($contract);
        });
    }

    public function destroy(Request $request, string $contractId, string $stepId, RemovePreparationStep $remove): JsonResponse
    {
        return $this->guard($request, 'le retrait d\'une étape de préparation', function () use ($contractId, $stepId, $remove) {
            [$contract, $step] = $this->resolve($contractId, $stepId);
            $remove($contract, $step);
            $this->journal->preparationStepRemoved($contract, $step->label);

            return AdminPreparationData::forContract($contract);
        });
    }

    public function attachProof(Request $request, string $contractId, string $stepId, AttachPreparationProof $attach): JsonResponse
    {
        return $this->guard($request, 'l\'envoi de la preuve d\'une étape', function () use ($request, $contractId, $stepId, $attach) {
            [$contract, $step] = $this->resolve($contractId, $stepId);
            $data = Validator::make($request->all(), [
                'file' => ['required', 'file', 'max:'.AttachPreparationProof::MAX_KILOBYTES, 'mimetypes:'.implode(',', AttachPreparationProof::MIME_TYPES)],
            ], self::MESSAGES)->validate();
            $attach($contract, $step, $data['file']);
            $this->journal->preparationProofAttached($step->refresh());

            return AdminPreparationData::forContract($contract);
        });
    }

    public function removeProof(Request $request, string $contractId, string $stepId, RemovePreparationProof $remove): JsonResponse
    {
        return $this->guard($request, 'le retrait de la preuve d\'une étape', function () use ($contractId, $stepId, $remove) {
            [$contract, $step] = $this->resolve($contractId, $stepId);
            $remove($contract, $step);
            $this->journal->preparationProofRemoved($step->refresh());

            return AdminPreparationData::forContract($contract);
        });
    }

    public function proof(Request $request, string $contractId, string $stepId): Response
    {
        try {
            [, $step] = $this->resolve($contractId, $stepId);
            if (! $step->proof_path || ! Storage::disk('local')->exists($step->proof_path)) {
                throw (new ModelNotFoundException)->setModel(VehicleContractPreparationStep::class);
            }

            return Storage::disk('local')->download($step->proof_path, $step->proof_original_name ?? 'preuve');
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'le téléchargement de la preuve d\'une étape');
        }
    }

    private const MESSAGES = [
        'label.required' => 'Le libellé de l\'étape est obligatoire.',
        'label.max' => 'Le libellé ne dépasse pas 120 caractères.',
        'done_on.date_format' => 'La date n\'est pas valide.',
        'done_on.before_or_equal' => 'La date ne peut pas être dans le futur.',
        'file.required' => 'Choisissez un fichier.',
        'file.max' => 'Le fichier dépasse 10 Mo.',
        'file.mimetypes' => 'Le fichier doit être un PDF ou une image (JPG, PNG, WEBP).',
    ];

    /** @return array{0: VehicleContract, 1: VehicleContractPreparationStep} — 404 sur l'étape d'un autre contrat */
    private function resolve(string $contractId, string $stepId): array
    {
        $contract = VehicleContract::findOrFail($contractId);
        $step = VehicleContractPreparationStep::where('vehicle_contract_id', $contract->id)->findOrFail($stepId);

        return [$contract, $step];
    }

    /** @param  callable(): AdminPreparationData  $run */
    private function guard(Request $request, string $what, callable $run): JsonResponse
    {
        try {
            return response()->json($run());
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, $what);
        }
    }

    private function failure(\Throwable $e, Request $request, string $what): JsonResponse
    {
        Log::error("Erreur lors de {$what} : ".$e->getMessage(), ['exception' => $e, 'user_id' => $request->user()?->id]);

        return response()->json(['message' => 'L\'étape de préparation n\'a pas pu être enregistrée. Réessayez.', 'code' => 'PREPARATION_STEP_FAILED'], 500);
    }
}
