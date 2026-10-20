<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Domain\PreparationSteps;
use App\Models\VehicleContract;
use App\Models\VehicleContractPreparationStep;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Joindre ou remplacer la preuve d'une étape (spec 2026-10-20, §4.2, §4.6). Le statut est relu
 * sous verrou AVANT de ranger le fichier : un contrat passé en service entre-temps ne garde rien.
 */
final class AttachPreparationProof
{
    public const MAX_KILOBYTES = 10240;

    public const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function __invoke(VehicleContract $contract, VehicleContractPreparationStep $step, UploadedFile $file): void
    {
        DB::transaction(function () use ($contract, $step, $file) {
            $contract = VehicleContract::query()->lockForUpdate()->findOrFail($contract->id);
            PreparationSteps::assertEditable($contract);
            $step = VehicleContractPreparationStep::query()->lockForUpdate()->findOrFail($step->id);
            PreparationSteps::assertNotAgentArrival($step, 'file');

            $path = $file->store("preparation/{$contract->id}", 'local');
            try {
                PreparationSteps::forgetProof($step);
                $step->fill([
                    'proof_path' => $path,
                    'proof_original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'proof_mime_type' => (string) $file->getMimeType(),
                    'proof_size' => (int) $file->getSize(),
                ])->save();
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }
        });
    }
}
