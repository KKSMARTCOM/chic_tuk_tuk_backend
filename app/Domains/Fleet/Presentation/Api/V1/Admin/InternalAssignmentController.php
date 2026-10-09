<?php

namespace App\Domains\Fleet\Presentation\Api\V1\Admin;

use App\Domains\Fleet\Application\Actions\AssignInternalDriver;
use App\Domains\Fleet\Application\Actions\EndInternalAssignment;
use App\Domains\Fleet\Application\Actions\ShowVehicleContractDetail;
use App\Domains\Fleet\Application\Data\AssignInternalDriverData;
use App\Domains\Fleet\Application\Data\EndInternalAssignmentData;
use App\Models\Driver;
use App\Models\InternalAssignment;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Les affectations internes (spec 2026-10-09, §5.1). */
final class InternalAssignmentController
{
    public function store(Request $request, string $contractId, AssignInternalDriverData $data, AssignInternalDriver $assign, ShowVehicleContractDetail $show): JsonResponse
    {
        return $this->guard($request, function () use ($request, $contractId, $data, $assign, $show) {
            $assign(VehicleContract::findOrFail($contractId), Driver::findOrFail($data->driverId), $data->startDate, $data->notes, $request->user());

            return response()->json($show($contractId), 201);
        });
    }

    public function end(Request $request, string $assignmentId, EndInternalAssignmentData $data, EndInternalAssignment $end, ShowVehicleContractDetail $show): JsonResponse
    {
        return $this->guard($request, function () use ($request, $assignmentId, $data, $end, $show) {
            $assignment = InternalAssignment::findOrFail($assignmentId);
            $end($assignment, $data->endDate, 'manual', $request->user());

            return response()->json($show($assignment->vehicle_contract_id));
        });
    }

    private function guard(Request $request, \Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur sur une affectation interne : '.$e->getMessage(), ['exception' => $e, 'user_id' => $request->user()?->id]);

            return response()->json(['message' => 'L\'affectation n\'a pas pu être enregistrée. Réessayez.', 'code' => 'INTERNAL_ASSIGNMENT_FAILED'], 500);
        }
    }
}
