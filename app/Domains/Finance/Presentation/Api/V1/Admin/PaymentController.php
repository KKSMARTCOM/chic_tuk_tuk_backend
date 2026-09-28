<?php

namespace App\Domains\Finance\Presentation\Api\V1\Admin;

use App\Domains\Finance\Application\Actions\CancelPayment;
use App\Domains\Finance\Application\Actions\CreatePayment;
use App\Domains\Finance\Application\Actions\DeletePayment;
use App\Domains\Finance\Application\Actions\ListPayableDrivers;
use App\Domains\Finance\Application\Actions\ListPayments;
use App\Domains\Finance\Application\Actions\ShowDriverPayments;
use App\Domains\Finance\Application\Actions\ShowPaymentDetail;
use App\Domains\Finance\Application\Actions\UpdatePayment;
use App\Domains\Finance\Application\Actions\ValidatePayment;
use App\Domains\Finance\Application\Data\CreatePaymentData;
use App\Domains\Finance\Application\Data\UpdatePaymentData;
use App\Models\Driver;
use App\Models\Payment;
use App\Shared\Http\ApiException;
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
    public function index(Request $request, ListPayments $list): JsonResponse
    {
        return $this->guard($request, 'la liste des paiements', 'La liste des paiements n\'a pas pu être chargée. Réessayez.', 'ADMIN_PAYMENTS_FAILED',
            fn () => response()->json($list($request->query())));
    }

    public function payableDrivers(Request $request, ListPayableDrivers $list): JsonResponse
    {
        return $this->guard($request, 'la liste des agents payables', 'Les agents n\'ont pas pu être chargés. Réessayez.', 'PAYABLE_DRIVERS_FAILED',
            fn () => response()->json($list()));
    }

    public function show(Request $request, string $paymentId, ShowPaymentDetail $show): JsonResponse
    {
        return $this->guard($request, 'la fiche du paiement', 'Ce paiement n\'a pas pu être chargé. Réessayez.', 'ADMIN_PAYMENT_FAILED',
            fn () => response()->json($show($paymentId)));
    }

    public function driverPayments(Request $request, string $driverId, ShowDriverPayments $show): JsonResponse
    {
        return $this->guard($request, 'les paiements de l\'agent', 'Les paiements de cet agent n\'ont pas pu être chargés. Réessayez.', 'DRIVER_PAYMENTS_FAILED',
            function () use ($driverId, $show) {
                Driver::findOrFail($driverId);

                return response()->json($show($driverId));
            });
    }

    public function store(Request $request, CreatePaymentData $data, ShowPaymentDetail $show, CreatePayment $create): JsonResponse
    {
        return $this->guard($request, 'la création du paiement', 'Ce paiement n\'a pas pu être enregistré.', 'PAYMENT_CREATE_FAILED',
            fn () => response()->json($show($create($data->toServicePayload())->id), 201));
    }

    public function update(Request $request, string $paymentId, UpdatePaymentData $data, ShowPaymentDetail $show, UpdatePayment $updatePayment): JsonResponse
    {
        return $this->guard($request, 'la modification du paiement', 'Ce paiement n\'a pas pu être modifié.', 'PAYMENT_UPDATE_FAILED',
            function () use ($paymentId, $data, $show, $updatePayment) {
                $updatePayment(Payment::findOrFail($paymentId), $data->toServicePayload());

                return response()->json($show($paymentId));
            });
    }

    public function validatePayment(Request $request, string $paymentId, ShowPaymentDetail $show, ValidatePayment $validate): JsonResponse
    {
        return $this->guard($request, 'la validation du paiement', 'Ce paiement n\'a pas pu être validé.', 'PAYMENT_VALIDATE_FAILED',
            function () use ($paymentId, $show, $validate) {
                $validate(Payment::findOrFail($paymentId));

                return response()->json($show($paymentId));
            });
    }

    public function cancel(Request $request, string $paymentId, ShowPaymentDetail $show, CancelPayment $cancelPayment): JsonResponse
    {
        return $this->guard($request, 'l\'annulation du paiement', 'Ce paiement n\'a pas pu être annulé.', 'PAYMENT_CANCEL_FAILED',
            function () use ($paymentId, $show, $cancelPayment) {
                $cancelPayment(Payment::findOrFail($paymentId));

                return response()->json($show($paymentId));
            });
    }

    public function destroy(Request $request, string $paymentId, DeletePayment $deletePayment): Response|JsonResponse
    {
        return $this->guard($request, 'la suppression du paiement', 'Ce paiement n\'a pas pu être supprimé.', 'PAYMENT_DELETE_FAILED',
            function () use ($paymentId, $deletePayment) {
                $deletePayment(Payment::findOrFail($paymentId));

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
