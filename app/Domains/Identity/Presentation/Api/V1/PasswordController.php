<?php

namespace App\Domains\Identity\Presentation\Api\V1;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Identity\Application\Actions\ChangePassword;
use App\Domains\Identity\Application\Actions\ResetPassword;
use App\Domains\Identity\Application\Actions\SendPasswordResetLinks;
use App\Domains\Identity\Application\Data\ChangePasswordData;
use App\Domains\Identity\Application\Data\ForgotPasswordData;
use App\Domains\Identity\Application\Data\ResetPasswordData;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mêmes règles que AuthController : les exceptions qui sont la réponse voulue de l'API
 * sont relancées en premier, et le message technique ne quitte jamais le journal.
 */
final class PasswordController
{
    public function change(ChangePasswordData $data, Request $request, ChangePassword $change, ActivityJournal $journal): Response
    {
        try {
            $change($request->user(), $data);
            $journal->passwordChanged($request->user());

            return response()->noContent();
        } catch (ValidationException|ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors du changement de mot de passe : '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Le changement de mot de passe a échoué pour une raison technique. Réessayez.',
                'code' => 'PASSWORD_CHANGE_FAILED',
            ], 500);
        }
    }

    /**
     * ⚠️ Seule méthode de l'API dont le catch **ne renvoie pas d'erreur**.
     *
     * Un échec ne peut survenir ici que pour une adresse EXISTANTE : les adresses
     * inconnues sortent de l'action sans rien tenter. Renvoyer un 500 spécifique
     * reviendrait donc à dire « ce compte existe », et rouvrirait l'énumération des
     * comptes que la réponse indifférenciée ferme. L'incident part au journal, et
     * l'appelant reçoit le même message que dans le cas nominal.
     */
    public function forgot(ForgotPasswordData $data, SendPasswordResetLinks $send): JsonResponse
    {
        try {
            $send($data);
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la demande de réinitialisation : '.$e->getMessage(), [
                'exception' => $e,
                'email' => $data->email,
            ]);
        }

        // Réponse volontairement identique, que l'adresse existe ou non, et que l'envoi
        // ait abouti ou non.
        return response()->json([
            'message' => "Si un compte existe pour cette adresse, un email vient d'être envoyé.",
        ]);
    }

    public function reset(ResetPasswordData $data, ResetPassword $reset, ActivityJournal $journal): Response
    {
        try {
            $journal->passwordReset($reset($data));

            return response()->noContent();
        } catch (ValidationException|ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la réinitialisation du mot de passe : '.$e->getMessage(), [
                'exception' => $e,
                // Jamais le jeton entier : il vaut un accès au compte.
                'token_prefix' => strtok($data->token, '.'),
            ]);

            return response()->json([
                'message' => 'La réinitialisation a échoué pour une raison technique. Redemandez un lien.',
                'code' => 'PASSWORD_RESET_FAILED',
            ], 500);
        }
    }
}
