<?php

namespace App\Http\Controllers\Web;

use App\Consts\Status;
use App\Http\Controllers\Controller;
use App\Services\BookingService;
use App\Services\TestimonialService;
use App\Services\ZoneService;
use App\Traits\Utils;
use Illuminate\Support\Facades\Auth;

class PageController extends Controller
{
    protected $testiomialService;
    protected $zoneService;
    protected $bookingService;

    public function __construct(
        TestimonialService $testiomialService,
        ZoneService $zoneService,
        BookingService $bookingService
    ) {
        $this->testiomialService = $testiomialService;
        $this->zoneService = $zoneService;
        $this->bookingService = $bookingService;
    }

    public function index()
    {

        $testimonials = $this->testiomialService->getTestimonials();

        $zones = $this->zoneService->getZones();

        /* $circuits = TouristCircuit::where('is_active', true)
            ->take(3)
            ->get(); */

        return view('pages.index', compact('testimonials', 'zones'));
    }

    public function install()
    {
        return view('pages.install');
    }

    public function availableBookings()
    {
        $driver   = Auth::user()->driver;
        $bookings = $this->bookingService->getAvailableBookings($driver->id);

        return view('pages.driver.bookings.available', compact('bookings'));
    }

    public function acceptingBookings()
    {
        $driver = Auth::user()->driver;
        $bookings = $this->bookingService->getByDriverId($driver->id, ['confirmed', 'in_progress']);

        return view('pages.driver.bookings.accepting', compact('bookings'));
    }

    public function historiesBookings()
    {
        $user = Auth::user();
        $perPage = 10;
        $globalSearch = request()->get('search');

        // Si c'est un Agent, afficher son historique
        if ($user->profil === 'driver' && $user->driver) {
            $query = \App\Models\Booking::query()
                ->where('driver_id', $user->driver->id)
                ->whereIn('status', ['completed', 'cancelled']);

            if ($globalSearch) {
                $query->where(function ($q) use ($globalSearch) {
                    $q->where('booking_number', 'LIKE', "%{$globalSearch}%")
                        ->orWhere('phone', 'LIKE', "%{$globalSearch}%")
                        ->orWhere('from_location', 'LIKE', "%{$globalSearch}%")
                        ->orWhere('to_location', 'LIKE', "%{$globalSearch}%");
                });
            }

            $bookings = $query->with(['user', 'driver.user'])
                ->orderByRaw("CONCAT(pickup_date, ' ', pickup_time) DESC")
                ->paginate($perPage)
                ->withQueryString();
        }
        // Si c'est un admin, afficher l'historique avec toutes les courses (incluant expired et missed)
        elseif ($user->profil === 'admin') {
            $query = \App\Models\Booking::query()->whereIn('status', ['completed', 'cancelled', 'expired', 'missed']);

            if ($globalSearch) {
                $query->where(function ($q) use ($globalSearch) {
                    $q->where('booking_number', 'LIKE', "%{$globalSearch}%")
                        ->orWhere('phone', 'LIKE', "%{$globalSearch}%")
                        ->orWhere('from_location', 'LIKE', "%{$globalSearch}%")
                        ->orWhere('to_location', 'LIKE', "%{$globalSearch}%");
                });
            }

            $bookings = $query->with(['user', 'driver.user'])
                ->orderByRaw("CONCAT(pickup_date, ' ', pickup_time) DESC")
                ->paginate($perPage)
                ->withQueryString();
        }
        // Pour les clients, afficher l'historique de leurs réservations
        else {
            $query = \App\Models\Booking::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['completed', 'cancelled']);

            if ($globalSearch) {
                $query->where(function ($q) use ($globalSearch) {
                    $q->where('booking_number', 'LIKE', "%{$globalSearch}%")
                        ->orWhere('phone', 'LIKE', "%{$globalSearch}%")
                        ->orWhere('from_location', 'LIKE', "%{$globalSearch}%")
                        ->orWhere('to_location', 'LIKE', "%{$globalSearch}%");
                });
            }

            $bookings = $query->with(['user', 'driver.user'])
                ->orderByRaw("CONCAT(pickup_date, ' ', pickup_time) DESC")
                ->paginate($perPage)
                ->withQueryString();
        }

        return view('pages.driver.bookings.history', compact('bookings'));
    }
}
