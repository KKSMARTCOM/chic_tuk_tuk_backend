<?php

namespace App\Domains\Finance\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Finance\Application\Actions\CancelPayment;
use App\Domains\Finance\Application\Actions\CancelPaymentsBatch;
use App\Domains\Finance\Application\Actions\CorrectCollectionDate;
use App\Domains\Finance\Application\Actions\CreatePayment;
use App\Domains\Finance\Application\Actions\DeletePayment;
use App\Domains\Finance\Application\Actions\ListPayableDrivers;
use App\Domains\Finance\Application\Actions\ListPayments;
use App\Domains\Finance\Application\Actions\PurgeCancelledPayments;
use App\Domains\Finance\Application\Actions\ShowPaymentDetail;
use App\Domains\Finance\Application\Actions\UpdatePayment;
use App\Domains\Finance\Application\Actions\ValidatePayment;
use App\Domains\Finance\Application\Actions\ValidatePaymentsBatch;
use App\Domains\Finance\Application\Data\CancelPaymentsBatchData;
use App\Domains\Finance\Application\Data\CorrectCollectionDateData;
use App\Domains\Finance\Application\Data\CreatePaymentData;
use App\Domains\Finance\Application\Data\UpdatePaymentData;
use App\Domains\Finance\Application\Data\ValidatePaymentData;
use App\Domains\Finance\Application\Data\ValidatePaymentsBatchData;
use App\Models\Driver;
use App\Models\Payment;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les paiements, vus de l'administration — ex-Admin\PaymentController (P2).
 *
 * Toutes les règles vivent dans les actions du domaine (ex-`PaymentService`) : plafonds,
 * états permis, notification de l'agent à la validation et à l'annulation. Les
 * écritures renvoient la fiche à jour.
 */
final class PaymentController
{
    /** Chaque écriture est tracée APRÈS sa réussite : voir `ActivityJournal`. */
    public function __construct(private readonly ActivityJournal $journal) {}

    public function index(Request $request, ListPayments $list): JsonResponse
    {
        return $this->guard($request, 'la liste des paiements', 'La liste des paiements n\'a pas pu être chargée. Réessayez.', 'ADMIN_PAYMENTS_FAILED',
            fn () => response()->json($list($request->query())));
    }

    public function payableDrivers(Request $request, ListPayableDrivers $list): JsonResponse
    {
        return $this->guard($request, 'la liste des agents payables', 'Les agents n\'ont pas pu être chargés. Réessayez.', 'PAYABLE_DRIVERS_FAILED',
            fn () => response()->json($list($request->query('type'))));
    }

    public function show(Request $request, string $paymentId, ShowPaymentDetail $show): JsonResponse
    {
        return $this->guard($request, 'la fiche du paiement', 'Ce paiement n\'a pas pu être chargé. Réessayez.', 'ADMIN_PAYMENT_FAILED',
            fn () => response()->json($show($paymentId)));
    }

    public function store(Request $request, CreatePaymentData $data, ShowPaymentDetail $show, CreatePayment $create): JsonResponse
    {
        return $this->guard($request, 'la création du paiement', 'Ce paiement n\'a pas pu être enregistré.', 'PAYMENT_CREATE_FAILED',
            function () use ($data, $show, $create) {
                $payment = $create($data->toServicePayload());
                $this->journal->paymentCreated($payment->load('driver.user'));

                return response()->json($show($payment->id), 201);
            });
    }

    public function update(Request $request, string $paymentId, UpdatePaymentData $data, ShowPaymentDetail $show, UpdatePayment $updatePayment): JsonResponse
    {
        return $this->guard($request, 'la modification du paiement', 'Ce paiement n\'a pas pu être modifié.', 'PAYMENT_UPDATE_FAILED',
            function () use ($paymentId, $data, $show, $updatePayment) {
                $updatePayment(Payment::findOrFail($paymentId), $data->toServicePayload());
                $this->journal->paymentUpdated(Payment::with('driver.user')->findOrFail($paymentId));

                return response()->json($show($paymentId));
            });
    }

    public function validatePayment(Request $request, string $paymentId, ValidatePaymentData $data, ShowPaymentDetail $show, ValidatePayment $validate): JsonResponse
    {
        return $this->guard($request, 'la validation du paiement', 'Ce paiement n\'a pas pu être validé.', 'PAYMENT_VALIDATE_FAILED',
            function () use ($paymentId, $data, $show, $validate) {
                $validate(Payment::findOrFail($paymentId), $data->collectedOn !== null ? Carbon::parse($data->collectedOn) : null);
                $this->journal->paymentValidated(Payment::with('driver.user')->findOrFail($paymentId));

                return response()->json($show($paymentId));
            });
    }

    /** Valider en groupe, à une date d'encaissement — silencieux par défaut (2026-10-01). */
    public function validateBatch(Request $request, ValidatePaymentsBatchData $data, ValidatePaymentsBatch $validate): JsonResponse
    {
        return $this->guard($request, 'la validation groupée des paiements', 'Les paiements n\'ont pas pu être validés.', 'PAYMENTS_BATCH_VALIDATE_FAILED',
            function () use ($data, $validate) {
                $count = $validate($data->paymentIds, Carbon::parse($data->collectedOn), $data->notifyDrivers);
                // En net, comme la génération : le journal parle le langage des fiches.
                $total = (float) Payment::whereIn('id', $data->paymentIds)->sum('net_amount');
                $this->journal->paymentsValidatedInBatch($count, $total, $data->collectedOn);

                return response()->json(['validated' => $count]);
            });
    }

    /** Annuler en groupe, avec un motif — silencieux par défaut (2026-10-01). */
    public function cancelBatch(Request $request, CancelPaymentsBatchData $data, CancelPaymentsBatch $cancel): JsonResponse
    {
        return $this->guard($request, 'l\'annulation groupée des paiements', 'Les paiements n\'ont pas pu être annulés.', 'PAYMENTS_BATCH_CANCEL_FAILED',
            function () use ($data, $cancel) {
                $count = $cancel($data->paymentIds, $data->reason, $data->notifyDrivers);
                $total = (float) Payment::whereIn('id', $data->paymentIds)->sum('net_amount');
                $this->journal->paymentsCancelledInBatch($count, $total, $data->reason);

                return response()->json(['cancelled' => $count]);
            });
    }

    /** Vider les paiements de contrat annulés — `purge-payments`, l'administrateur seul (2026-10-01). */
    public function purgeCancelled(Request $request, PurgeCancelledPayments $purge): JsonResponse
    {
        return $this->guard($request, 'la purge des paiements annulés', 'Les paiements annulés n\'ont pas pu être vidés.', 'PAYMENTS_PURGE_FAILED',
            function () use ($purge) {
                ['count' => $count, 'total' => $total] = $purge();
                if ($count > 0) {
                    $this->journal->cancelledPaymentsPurged($count, $total);
                }

                return response()->json(['deleted' => $count]);
            });
    }

    public function correctCollectionDate(Request $request, string $paymentId, CorrectCollectionDateData $data, ShowPaymentDetail $show, CorrectCollectionDate $correct): JsonResponse
    {
        return $this->guard($request, 'la correction de la date d\'encaissement', 'La date d\'encaissement n\'a pas pu être corrigée.', 'PAYMENT_COLLECTED_ON_FAILED',
            function () use ($paymentId, $data, $show, $correct) {
                $correct(Payment::findOrFail($paymentId), Carbon::parse($data->collectedOn));
                $this->journal->paymentUpdated(Payment::with('driver.user')->findOrFail($paymentId));

                return response()->json($show($paymentId));
            });
    }

    public function cancel(Request $request, string $paymentId, ShowPaymentDetail $show, CancelPayment $cancelPayment): JsonResponse
    {
        return $this->guard($request, 'l\'annulation du paiement', 'Ce paiement n\'a pas pu être annulé.', 'PAYMENT_CANCEL_FAILED',
            function () use ($paymentId, $show, $cancelPayment) {
                $cancelPayment(Payment::findOrFail($paymentId));
                $this->journal->paymentCancelled(Payment::with('driver.user')->findOrFail($paymentId));

                return response()->json($show($paymentId));
            });
    }

    public function destroy(Request $request, string $paymentId, DeletePayment $deletePayment): Response|JsonResponse
    {
        return $this->guard($request, 'la suppression du paiement', 'Ce paiement n\'a pas pu être supprimé.', 'PAYMENT_DELETE_FAILED',
            function () use ($paymentId, $deletePayment) {
                $payment = Payment::with('driver.user')->findOrFail($paymentId);
                $label = $this->journal->paymentLabel($payment);
                $deletePayment($payment);
                $this->journal->paymentDeleted($payment->id, $label);

                return response()->noContent();
            });
    }

    /**
     * Les trois règles de try/catch de l'API v1, une seule fois : relancer les erreurs
     * métier et de validation intactes, attraper `\Throwable`, et ne mettre le message
     * d'origine qu'au journal.
     */
    private function guard(Request $request, string $what, string $message, string $code, \Closure $action): Response|JsonResponse
    {
        try {
            return $action();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("Erreur lors de {$what} : ".$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json(['message' => $message, 'code' => $code], 500);
        }
    }
}
