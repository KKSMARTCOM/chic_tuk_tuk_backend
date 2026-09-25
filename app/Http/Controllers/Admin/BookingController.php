<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\TouristCircuit;
use App\Models\User;
use App\Models\Zone;
use App\Services\BookingService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    protected $bookingService;
    protected $pricingService;

    public function __construct(BookingService $bookingService, PricingService $pricingService)
    {
        $this->bookingService = $bookingService;
        $this->pricingService = $pricingService;
    }

    public function index(Request $request)
    {
        $query = Booking::with(['user', 'driver']);

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('booking_number', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%")
                    ->orWhere('from_location', 'LIKE', "%{$search}%")
                    ->orWhere('to_location', 'LIKE', "%{$search}%");
            });
        }

        $sort     = in_array($request->sort, ['asc', 'desc']) ? $request->sort : 'desc';
        $bookings = $query->orderBy('created_at', $sort)->get();

        return view('pages.admin.bookings.index', compact('bookings'));
    }

    public function create()
    {
        $zones = Zone::all();
        $touristCircuits = TouristCircuit::all();

        return view('pages.admin.bookings.create', compact('zones', 'touristCircuits'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate(
            [
                'client_name' => 'nullable|string|max:255',
                'phone' => 'required|string|max:20',

                'from_location' => 'required',
                'to_location' => 'required',
                'from_lat' => 'required_with:from_location|numeric',
                'from_lng' => 'required_with:from_location|numeric',
                'to_lat' => 'required_with:to_location|numeric',
                'to_lng' => 'required_with:to_location|numeric',

                'pickup_date' => 'required|date',
                'pickup_time' => 'required|date_format:H:i',
                'days' => 'nullable|integer|min:1',
                'round_trip' => 'nullable|boolean',
                'return_time' => 'nullable|date_format:H:i',
                'week_days' => 'nullable|in:lun_ven,lun_sam,lun_dim',
                'base_price' => 'required|numeric|min:0',
                'status' => 'nullable|in:pending,confirmed,in_progress,completed,cancelled',
                'tourist_circuit_id' => 'nullable|exists:tourist_circuits,id',
                'special_requests' => 'nullable|string|max:1000',
            ],
            [
                'from_location.required' => 'Veuillez sélectionner une ville de départ.',
                'to_location.required' => 'Veuillez sélectionner une ville de destination.',
                'from_lat.required_with' => 'Ville de départ manquantes.',
                'to_lat.required_with' => 'Ville de destination manquantes.',
                'tourist_circuit_id.exists' => 'Le circuit touristique sélectionné n\'existe pas.',
            ]
        );

        try {
            $createData = [
                'client_name' => $request->client_name,
                'user_id' => Auth::user()->id,
                'from_location' => $request->from_location,
                'to_location' => $request->to_location,
                'from_lng' => $request->from_lng,
                'from_lat' => $request->from_lat,
                'to_lng' => $request->to_lng,
                'to_lat' => $request->to_lat,
                'phone' => $request->phone,
                'days' => $request->days ?? 1,
                'round_trip' => $request->round_trip,
                'return_time' => $request->return_time,
                'week_days' => $request->week_days,
                'pickup_date' => $request->pickup_date,
                'pickup_time' => $request->pickup_time,
                'special_requests' => $request->special_requests,
                'tourist_circuit_id' => $request->tourist_circuit_id,
                'base_price' => $request->base_price,
                'status' => $request->status ?? 'pending',
            ];

            $booking = $this->bookingService->create($createData);

            return redirect()->route('admin.bookings.show', $booking)->with('success', 'Réservation créée avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de la réservation: ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->withErrors(['error' => 'Une erreur est survenue: ' . $e->getMessage()])->withInput();
        }
    }

    public function show(Booking $booking)
    {
        $booking->load([
            'user',
            'driver',
            'touristCircuit',
            'promoCode',
            'parentBooking',
            'childBookings',
            'subscriptionDriver.user'
        ]);

        $availableDrivers = User::where('profil', 'driver')
            ->where('is_active', true)
            ->with('driver')
            ->get();

        return view('pages.admin.bookings.show', compact('booking', 'availableDrivers'));
    }

    public function assignDriver(Request $request, Booking $booking)
    {
        try {
            $validated = $request->validate(
                [
                    'driver_id' => 'required|exists:drivers,id',
                ],
                [
                    'driver_id.exists' => 'Le Agent sélectionné n\'existe pas.',
                ]
            );

            $driver = Driver::findOrFail($validated['driver_id']);

            $user = $driver->user;

            if ($user->profil !== 'driver' || !$user->is_active) {
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => 'Ce Agent n\'est pas disponible'], 400);
                }

                return back()->with('error', 'Ce Agent n\'est pas disponible.');
            }

            $this->bookingService->take($booking->id, $driver->id);

            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'message' => 'Agent assigné avec succès']);
            }

            return back()->with('success', 'Agent assigné avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'assignation du Agent: ' . $e->getMessage(), ['exception' => $e]);
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Erreur lors de l\'assignation du Agent: ' . $e->getMessage()], 400);
            }

            return back()->with('error', 'Une erreur est survenue: ' . $e->getMessage());
        }
    }

    /**
     * Change le titulaire d'un abonnement déjà pris : voir
     * `BookingService::transferSubscription()` pour ce qui passe au nouvel agent.
     */
    public function transferSubscription(Request $request, Booking $booking)
    {
        $validated = $request->validate(
            ['driver_id' => 'required|exists:drivers,id'],
            ['driver_id.required' => 'Sélectionnez le nouvel agent.', 'driver_id.exists' => "Cet agent n'existe pas."]
        );

        try {
            $this->bookingService->transferSubscription($booking->id, $validated['driver_id']);

            return response()->json(['success' => true, 'message' => 'Abonnement transféré au nouvel agent.']);
        } catch (\Exception $e) {
            Log::error("Erreur lors du transfert d'abonnement : " . $e->getMessage(), ['exception' => $e]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function removeDriver(Booking $booking)
    {
        try {
            if (!$booking->driver_id) {
                if (request()->wantsJson()) {
                    return response()->json(['success' => false, 'message' => 'Aucun Agent assigné à cette réservation'])->setStatusCode(400);
                }

                return back()->with('error', 'Aucun Agent assigné à cette réservation');
            }

            if ($booking->status !== 'confirmed' && $booking->status !== 'in_progress') {
                if (request()->wantsJson()) {
                    return response()->json(['success' => false, 'message' => 'Impossible de retirer le Agent pour ce statut de réservation'], 400);
                }

                return back()->with('error', 'Impossible de retirer le Agent pour ce statut de réservation');
            }

            $data = [
                '_partial'  => true,
                'driver_id' => null,
                'status' => 'pending',
            ];

            $this->bookingService->update($booking, $data);

            if (request()->wantsJson()) {
                return response()->json(['success' => true, 'message' => 'Agent retiré avec succès']);
            }

            return back()->with('success', 'Agent retiré avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur lors du retrait du Agent: ' . $e->getMessage(), ['exception' => $e]);
            if (request()->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Erreur lors du retrait du Agent: ' . $e->getMessage()], 400);
            }

            return back()->with('error', 'Une erreur est survenue: ' . $e->getMessage());
        }
    }

    public function updateStatus(Request $request, Booking $booking)
    {
        try {
            $validated = $request->validate(
                [
                    'status' => 'required|in:pending,confirmed,in_progress,completed,cancelled',
                    'cancellation_reason' => 'nullable|string|max:1000',
                ],
                [
                    'status.in' => 'Le statut sélectionné est invalide.',
                ]
            );

            $updateData = ['_partial'  => true, 'status' => $validated['status']];

            if ($validated['status'] === 'cancelled') {
                if (in_array($booking->status, ['in_progress'])) {
                    throw new \Exception('Impossible d\'annuler la réservation.');
                }

                $updateData['cancelled_at'] = now();
                if (!empty($validated['cancellation_reason'])) {
                    $updateData['cancellation_reason'] = $validated['cancellation_reason'];
                }
            }

            $this->bookingService->update($booking, $updateData);

            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'message' => 'Statut mis à jour avec succès']);
            }

            return back()->with('success', 'Statut mis à jour avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour du statut: ' . $e->getMessage(), ['exception' => $e]);
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour du statut: ' . $e->getMessage()], 400);
            }

            return back()->with('error', 'Une erreur est survenue: ' . $e->getMessage());
        }
    }

    public function edit(Booking $booking)
    {
        $booking->load(['user', 'driver', 'touristCircuit', 'promoCode']);
        $zones = Zone::all();
        $touristCircuits = TouristCircuit::all();

        return view('pages.admin.bookings.edit', compact('booking', 'zones', 'touristCircuits'));
    }

    public function update(Request $request, Booking $booking)
    {
        $validated = $request->validate(
            [
                'client_name' => 'nullable|string|max:255',
                'user_id' => 'nullable|exists:users,id',
                'phone' => 'required|string|max:20',

                'from_location' => 'required',
                'to_location' => 'required',
                'from_lat' => 'required_with:from_location|numeric',
                'from_lng' => 'required_with:from_location|numeric',
                'to_lat' => 'required_with:to_location|numeric',
                'to_lng' => 'required_with:to_location|numeric',

                'pickup_date' => 'required|date',
                'pickup_time' => 'required|date_format:H:i',
                'days' => 'nullable|integer|min:1',
                'round_trip' => 'nullable|boolean',
                'return_time' => 'nullable|date_format:H:i',
                'week_days' => 'nullable|in:lun_ven,lun_sam,lun_dim',
                'base_price' => 'required|numeric|min:0',
                'status' => 'required|in:pending,confirmed,in_progress,completed,cancelled',
                'tourist_circuit_id' => 'nullable|exists:tourist_circuits,id',
                'special_requests' => 'nullable|string|max:1000',
            ],
            [
                'from_location.required' => 'Veuillez sélectionner une ville de départ.',
                'to_location.required' => 'Veuillez sélectionner une ville de destination.',
                'from_lat.required_with' => 'Ville de départ manquantes.',
                'to_lat.required_with' => 'Ville de destination manquantes.',

                'user_id.exists' => 'L\'utilisateur sélectionné n\'existe pas.',
                'tourist_circuit_id.exists' => 'Le circuit touristique sélectionné n\'existe pas.',

                'base_price.required' => 'Le prix de base est requis.',
                'base_price.numeric' => 'Le prix de base doit être un nombre.',
            ]
        );

        try {
            $updateData = [
                'client_name' => $request->client_name,
                'user_id' => $request->user_id,

                'from_location' => $request->from_location,
                'to_location' => $request->to_location,
                'from_lng' => $request->from_lng,
                'from_lat' => $request->from_lat,
                'to_lng' => $request->to_lng,
                'to_lat' => $request->to_lat,

                'phone' => $request->phone,
                'days' => $request->days,
                'round_trip' => $request->boolean('round_trip'),
                'return_time' => $request->boolean('round_trip') ? $request->return_time : null,
                'week_days' => $request->week_days,
                'pickup_date' => $request->pickup_date,
                'pickup_time' =>  $request->pickup_time,
                'status' => $request->status,
                'special_requests' => $request->special_requests,
                'tourist_circuit_id' => $request->tourist_circuit_id,

                'base_price' => $request->base_price,
            ];

            $this->bookingService->update($booking, $updateData);

            return redirect()->route('admin.bookings.show', $booking)->with('success', 'Réservation mise à jour avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de la réservation: ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->withErrors(['error' => 'Une erreur est survenue: ' . $e->getMessage()])->withInput();
        }
    }

    public function destroy(string $bookingId)
    {
        try {
            $this->bookingService->delete($bookingId);
            return redirect()->route('admin.bookings.index')->with('success', 'La course a été supprimée avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression de la réservation: ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
