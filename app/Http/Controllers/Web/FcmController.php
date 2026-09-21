<?php

namespace App\Http\Controllers\Web;

use App\Domains\Notification\Application\Actions\RegisterDevice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Enregistrement d'un appareil depuis le chemin Blade.
 *
 * ⚠️ Délègue à `RegisterDevice` plutôt que d'appeler `updateOrCreate` lui-même, comme
 * `BookingService` délègue aux actions du sous-lot 3a : deux implémentations de la même
 * règle finiraient par diverger, et c'est justement ici que la divergence coûterait
 * cher — la réattribution d'un appareil à un nouveau propriétaire est la partie subtile.
 *
 * ⚠️ Cet endpoint levait une QueryException à CHAQUE appel jusqu'au 2026-09-21 :
 * `FcmToken` ne portait pas le trait `HasUuid` alors que sa clé primaire est un `uuid`
 * NOT NULL, donc l'insertion partait avec `id` à NULL. Aucun appareil n'a jamais pu être
 * enregistré, et le seul déclencheur de notification de l'application parcourait
 * toujours une liste vide.
 */
class FcmController extends Controller
{
    public function store(Request $request, RegisterDevice $enregistrer)
    {
        $request->validate(['token' => 'required|string|max:255']);

        $enregistrer(Auth::user(), $request->string('token')->toString());

        return response()->json(['success' => true]);
    }
}
