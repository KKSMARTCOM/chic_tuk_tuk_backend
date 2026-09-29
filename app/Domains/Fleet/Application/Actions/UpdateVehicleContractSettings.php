<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\UpdateVehicleContractSettingsData;
use App\Domains\Fleet\Application\Data\VehicleContractSettingsData;
use App\Domains\Fleet\Domain\ContractTerms;
use App\Models\VehicleContractTerm;
use Illuminate\Support\Facades\DB;

/**
 * Enregistrer les réglages des contrats véhicule : la liste complète des durées, et les
 * charges par défaut.
 *
 * Les durées se retrouvent par leur nombre de mois : une durée reprise est mise à jour,
 * une durée nouvelle est créée, une durée absente est supprimée. Aucun contrat n'est
 * touché — chacun a copié ses montants à sa création.
 */
final class UpdateVehicleContractSettings
{
    public function __construct(private readonly ShowVehicleContractSettings $show) {}

    public function __invoke(UpdateVehicleContractSettingsData $data): VehicleContractSettingsData
    {
        DB::transaction(function () use ($data) {
            $months = collect($data->terms)->pluck('months')->map(fn ($m) => (int) $m);

            VehicleContractTerm::query()->whereNotIn('months', $months)->delete();

            foreach ($data->terms as $term) {
                VehicleContractTerm::query()->updateOrCreate(
                    ['months' => (int) $term['months']],
                    [
                        'total_amount' => $term['total_amount'],
                        'daily_amount' => $term['daily_amount'],
                        'daily_tax' => $term['daily_tax'],
                    ],
                );
            }

            ContractTerms::chargeDefaults()->update([
                'unlimited_internet' => $data->unlimitedInternet,
                'spotify_premium' => $data->spotifyPremium,
                'manager_remuneration' => $data->managerRemuneration,
            ]);
        });

        return ($this->show)();
    }
}
