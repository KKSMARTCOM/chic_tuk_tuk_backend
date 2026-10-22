<?php

namespace App\Domains\Finance\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Finance\Application\Actions\CancelDisbursement;
use App\Domains\Finance\Application\Actions\ListDisbursableContracts;
use App\Domains\Finance\Application\Actions\RecordDisbursement;
use App\Domains\Finance\Application\Actions\UpdateDisbursement;
use App\Domains\Finance\Application\Data\AdminDisbursementsData;
use App\Domains\Finance\Application\Data\DisbursableContractsData;
use App\Domains\Fleet\Application\Actions\AttachPreparationProof;
use App\Domains\Notification\Application\Notifier;
use App\Models\OwnerDisbursement;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les versements au propriétaire, côté administration (spec 2026-10-22-versement-proprietaire,
 * §5.1). Le contrôleur trace et notifie, d'après ce que rendent les actions.
 */
final class DisbursementController
{
    public function __construct(
        private readonly ActivityJournal $journal,
        private readonly Notifier $notifier,
    ) {}

    public function index(Request $request, string $contractId): JsonResponse
    {
        return $this->guard($request, 'la lecture des versements', fn () => AdminDisbursementsData::forContract(VehicleContract::findOrFail($contractId)));
    }

    /** Les contrats proposés au type « Versement propriétaire » de l'ajout d'un paiement. */
    public function contracts(Request $request, ListDisbursableContracts $list): JsonResponse
    {
        return $this->guard($request, 'la lecture des contrats à verser', fn () => $list());
    }

    public function store(Request $request, string $contractId, RecordDisbursement $record): JsonResponse
    {
        return $this->guard($request, 'l\'enregistrement d\'un versement', function () use ($request, $contractId, $record) {
            $contract = VehicleContract::with('vehicle')->findOrFail($contractId);
            $data = Validator::make($request->all(), $this->rules($contract), self::MESSAGES)->validate();
            $disbursement = $record($contract, $data, $request->file('proof'), $request->user());
            $this->journal->disbursementRecorded($disbursement);
            // Après la transaction de l'action : une notification ne fait jamais échouer l'écriture.
            $this->notifier->disbursementRecorded($disbursement);

            return AdminDisbursementsData::forContract($contract);
        });
    }

    public function update(Request $request, string $id, UpdateDisbursement $update): JsonResponse
    {
        return $this->guard($request, 'la correction d\'un versement', function () use ($request, $id, $update) {
            $disbursement = OwnerDisbursement::with('contract')->findOrFail($id);
            UpdateDisbursement::assertRecorded($disbursement);
            $data = Validator::make($request->all(), [
                ...$this->rules($disbursement->contract, partial: true),
                'remove_proof' => ['sometimes', 'boolean'],
            ], self::MESSAGES)->validate();
            $changes = $update($disbursement, $data, $request->file('proof'), (bool) ($data['remove_proof'] ?? false), $request->user());
            if ($changes !== []) {
                $this->journal->disbursementUpdated($disbursement->refresh(), $changes);
            }

            return AdminDisbursementsData::forContract($disbursement->contract);
        });
    }

    public function cancel(Request $request, string $id, CancelDisbursement $cancel): JsonResponse
    {
        return $this->guard($request, 'l\'annulation d\'un versement', function () use ($request, $id, $cancel) {
            $disbursement = OwnerDisbursement::with('contract')->findOrFail($id);
            $data = Validator::make($request->all(), ['reason' => ['required', 'string', 'max:1000']], [
                'reason.required' => 'Le motif de l\'annulation est obligatoire.',
                'reason.max' => 'Le motif ne dépasse pas 1 000 caractères.',
            ])->validate();
            $this->journal->disbursementCancelled($cancel($disbursement, $data['reason'], $request->user()));

            return AdminDisbursementsData::forContract($disbursement->contract);
        });
    }

    public function proof(Request $request, string $id): Response
    {
        try {
            $disbursement = OwnerDisbursement::findOrFail($id);
            if (! $disbursement->proof_path || ! Storage::disk('local')->exists($disbursement->proof_path)) {
                throw (new ModelNotFoundException)->setModel(OwnerDisbursement::class);
            }

            return Storage::disk('local')->download($disbursement->proof_path, $disbursement->proof_original_name ?? 'preuve');
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'le téléchargement de la preuve d\'un versement');
        }
    }

    /** @return array<string, mixed> les règles d'un versement (§4.2) ; `sometimes` pour une correction */
    private function rules(VehicleContract $contract, bool $partial = false): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'paid_on' => [...$required, 'date_format:Y-m-d', 'before_or_equal:today',
                ...($contract->start_date ? ['after_or_equal:'.$contract->start_date->toDateString()] : [])],
            'amount' => [...$required, 'numeric', 'gt:0', 'decimal:0,2'],
            'method' => [...$required, Rule::in(['mobile_money', 'bank_transfer'])],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'proof' => ['sometimes', 'nullable', 'file', 'max:'.AttachPreparationProof::MAX_KILOBYTES, 'mimetypes:'.implode(',', AttachPreparationProof::MIME_TYPES)],
        ];
    }

    private const MESSAGES = [
        'paid_on.required' => 'La date du versement est obligatoire.',
        'paid_on.date_format' => 'La date n\'est pas valide.',
        'paid_on.before_or_equal' => 'La date ne peut pas être dans le futur.',
        'paid_on.after_or_equal' => 'La date ne peut pas précéder le début du contrat.',
        'amount.required' => 'Le montant est obligatoire.',
        'amount.gt' => 'Le montant doit être supérieur à 0.',
        'amount.numeric' => 'Le montant n\'est pas valide.',
        'amount.decimal' => 'Le montant a deux décimales au plus.',
        'method.required' => 'Choisissez le moyen du versement.',
        'method.in' => 'Le moyen doit être Mobile Money ou virement.',
        'reference.max' => 'La référence ne dépasse pas 100 caractères.',
        'note.max' => 'La note ne dépasse pas 1 000 caractères.',
        'proof.max' => 'Le fichier dépasse 10 Mo.',
        'proof.mimetypes' => 'Le fichier doit être un PDF ou une image (JPG, PNG, WEBP).',
    ];

    /** @param  callable(): (AdminDisbursementsData|DisbursableContractsData)  $run */
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

        return response()->json(['message' => 'Le versement n\'a pas pu être enregistré. Réessayez.', 'code' => 'ADMIN_DISBURSEMENT_FAILED'], 500);
    }
}
