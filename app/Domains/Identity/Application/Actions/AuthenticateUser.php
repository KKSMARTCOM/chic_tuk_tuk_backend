<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\LoginData;
use App\Domains\Identity\Application\IssuedToken;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class AuthenticateUser
{
    public function __invoke(LoginData $data, string $ip, ?string $userAgent = null): IssuedToken
    {
        $accounts = User::query()
            ->where('email', $data->email)
            ->when($data->profil, fn ($query, $profil) => $query->where('profil', $profil->value))
            ->get();

        if ($accounts->isEmpty()) {
            $this->fail();
        }

        // Les comptes verrouillés sont écartés, pas cause de refus global : refuser
        // dès qu'un seul l'est permettrait de bloquer un compte en s'acharnant sur
        // un autre, l'attaquant ne connaissant que l'email.
        [$locked, $candidates] = $accounts->partition(
            fn (User $user) => $user->locked_until !== null && $user->locked_until->isFuture(),
        );

        if ($candidates->isEmpty()) {
            $this->refuseLocked($locked);
        }

        $matches = $candidates->filter(fn (User $user) => Hash::check($data->password, $user->password));

        if ($matches->isEmpty()) {
            $this->registerFailure($data->email);
            $this->fail();
        }

        if ($matches->count() > 1) {
            // Aucun jeton émis, aucun compteur touché : le mot de passe était bon,
            // il manque seulement l'information du profil visé. Renvoyer la liste
            // ne divulgue rien, puisque l'appelant vient de prouver le mot de passe.
            throw new ApiException(
                status: 409,
                errorCode: 'PROFIL_AMBIGUOUS',
                message: 'Plusieurs comptes utilisent ces identifiants. Précisez le profil.',
                extra: ['profils' => $matches->pluck('profil')->sort()->values()->all()],
            );
        }

        $user = $matches->first();

        if (! $user->is_active) {
            throw new ApiException(
                status: 403,
                errorCode: 'ACCOUNT_DISABLED',
                message: 'Votre compte a été désactivé. Contactez l\'administrateur.',
            );
        }

        $this->resetCounters($user, $ip);

        return new IssuedToken($user, $this->issueToken($user, $ip, $userAgent));
    }

    /**
     * @param  Collection<int, User>  $locked
     */
    private function refuseLocked(Collection $locked): never
    {
        // Le verrou qui expire le plus tôt : c'est le premier moment où l'utilisateur
        // pourra réessayer.
        $until = $locked->min('locked_until');
        $seconds = max(0, (int) now()->diffInSeconds($until, false));

        throw new ApiException(
            status: 423,
            errorCode: 'ACCOUNT_LOCKED',
            message: "Compte temporairement verrouillé. Réessayez dans {$seconds} secondes.",
            extra: ['retry_after' => $seconds],
            headers: ['Retry-After' => (string) $seconds],
        );
    }

    /**
     * Incrémente le compteur de TOUS les comptes portant cet email. L'unicité
     * (email, profil) fait qu'ils appartiennent à la même personne : ils se
     * verrouillent donc ensemble, ce qui est cohérent et évite qu'un attaquant
     * puisse choisir sa cible, puisqu'il ne connaît que l'email.
     */
    private function registerFailure(string $email): void
    {
        $max = (int) config('identity.lock.max_attempts');
        $minutes = (int) config('identity.lock.minutes');

        User::query()->where('email', $email)->get()->each(function (User $user) use ($max, $minutes) {
            $attempts = $user->failed_login_attempts + 1;

            $user->forceFill([
                'failed_login_attempts' => $attempts,
                'last_failed_login' => now(),
            ]);

            if ($attempts >= $max) {
                $user->forceFill(['locked_until' => now()->addMinutes($minutes)]);

                Log::warning('Compte verrouillé (API)', [
                    'user_id' => $user->id,
                    'profil' => $user->profil,
                ]);
            }

            $user->saveQuietly();
        });
    }

    /**
     * L'appareil et l'adresse sont retenus pour que l'utilisateur reconnaisse ses
     * sessions dans la liste des appareils connectés, et révoque la bonne.
     */
    private function issueToken(User $user, string $ip, ?string $userAgent): string
    {
        $issued = $user->createToken(
            name: (string) config('identity.token.name'),
            abilities: [$user->profil],
            expiresAt: now()->addDays((int) config('identity.token.absolute_days')),
        );

        $issued->accessToken->forceFill([
            'ip_address' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 1000) : null,
        ])->save();

        return $issued->plainTextToken;
    }

    private function resetCounters(User $user, string $ip): void
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_failed_login' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->saveQuietly();

        Log::info('Connexion API réussie', [
            'user_id' => $user->id,
            'profil' => $user->profil,
            'ip' => $ip,
        ]);
    }

    /**
     * Message volontairement indifférencié : ne jamais distinguer « email inconnu »
     * de « mot de passe faux », sous peine de transformer l'endpoint en test
     * d'existence de compte. Le nombre de tentatives restantes n'est pas révélé non
     * plus, contrairement au formulaire Blade.
     */
    private function fail(): never
    {
        throw ValidationException::withMessages([
            'email' => ['Identifiants incorrects.'],
        ]);
    }
}
