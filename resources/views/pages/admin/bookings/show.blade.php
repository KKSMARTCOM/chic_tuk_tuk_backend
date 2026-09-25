@extends('layouts.app')

@php
    $isParent = $booking->is_subscription_parent;
    $isChild = $booking->is_subscription_child;
    $isUnique = !$isParent && !$isChild;
    // Abonnement parent encore actif (des jours restants — ou des trajets à rattraper —
    // même si J1 complété)
    $isActiveSubscription =
        $isParent &&
        ($booking->remaining_days > 1 || $booking->makeup_go_count > 0 || $booking->makeup_return_count > 0) &&
        !in_array($booking->status, ['cancelled', 'expired']);
    // Peut-on annuler ?
    $canCancel =
        // Course unique ou enfant non terminée
        (($isUnique || $isChild) && $booking->canBeCancelled()) ||
        // Abonnement parent avec jours restants
        $isActiveSubscription;
    // Peut-on assigner ?
    $canAssign = !$booking->driver_id && in_array($booking->status, ['pending']) && !$isChild;
    // Peut-on retirer l'agent ?
$canRemoveDriver = $booking->driver_id && !in_array($booking->status, ['completed', 'cancelled', 'expired', 'missed']);
// Peut-on transférer l'abonnement à un autre agent ? Seulement un parent déjà pris, tant
// qu'il reste des courses à faire.
$canTransfer =
    $isParent &&
    $booking->subscription_driver_id &&
    !in_array($booking->status, ['cancelled', 'expired']) &&
    ($booking->remaining_days > 1 ||
        $booking->makeup_go_count > 0 ||
        $booking->makeup_return_count > 0 ||
        $booking->childBookings->whereIn('status', ['pending', 'confirmed'])->isNotEmpty());
// Peut-on supprimer ?
$canDelete = in_array($booking->status, ['cancelled', 'expired']);
@endphp

@section('content')
    <!-- Header -->
    <div class="bg-white rounded-lg shadow-md mb-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <div>
                <h1 class="text-lg md:text-2xl font-bold text-gray-800">Détails de la Réservation</h1>
                <p class="text-sm md:text-base text-gray-600">N° {{ $booking->booking_number }}</p>
            </div>
            <div class="block md:flex space-x-3">
                @if ($booking->status === 'pending')
                    <a href="{{ route('admin.bookings.edit', $booking) }}"
                        class="bg-purple-600 text-white block px-4 py-2 rounded-lg hover:bg-purple-700 transition">
                        <i class="fas fa-edit mr-2"></i> Modifier
                    </a>
                @endif

                <a href="{{ route('admin.bookings.index') }}"
                    class="bg-gray-600 text-white block mt-2 md:mt-0 px-4 py-2 rounded-lg hover:bg-gray-700 transition">
                    <i class="fas fa-arrow-left mr-2"></i> Retour
                </a>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Informations principales -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Détails de la réservation -->
            <div class="bg-white rounded-lg shadow-md">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Informations de la Réservation</h3>

                    {{-- Badge type de course --}}
                    @php
                        $isParent = $booking->is_subscription_parent;
                        $isChild = $booking->is_subscription_child;
                        $isSimpleReturn = $booking->is_simple_return;
                        $isReturn = $booking->trip_type === 'return';
                    @endphp

                    <div class="mt-2 max-w-[250px] w-full flex flex-wrap gap-2">

                        @if ($isParent)
                            <span
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700 border border-emerald-200">
                                <i class="fas fa-rotate"></i>
                                Abonn parent · {{ $booking->days }}j
                            </span>
                        @elseif ($isChild)
                            <span
                                class="flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-teal-50 text-teal-700 border border-teal-200 max-w-[200px]">
                                <i class="fas fa-link shrink-0"></i>
                                <span class="truncate">{{ $booking->subscription_label }}</span>
                            </span>
                        @elseif ($isSimpleReturn)
                            {{-- Course retour d'une course simple aller-retour --}}
                            <span
                                class="flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-orange-50 text-orange-600 border border-orange-200 max-w-[200px]">
                                <i class="fas fa-arrow-left shrink-0"></i>
                                <span class="truncate">
                                    Retour —
                                    {{ $booking->parentBooking?->client_name ??
                                        ($booking->parentBooking?->user?->name ?? ($booking->parentBooking?->booking_number ?? 'N/A')) }}
                                </span>
                            </span>
                        @else
                            <span
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-600 border border-blue-200">
                                <i class="fas fa-car-side"></i>
                                Course unique
                            </span>
                        @endif

                        {{-- Badge aller-retour --}}
                        @if ($booking->round_trip && !$isSimpleReturn)
                            @if ($isReturn)
                                <span title="Retour"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-orange-50 text-orange-600 border border-orange-200">
                                    <i class="fas fa-arrow-left"></i>
                                </span>
                            @else
                                <span title="Aller-Retour"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-purple-50 text-purple-600 border border-purple-200">
                                    <i class="fas fa-arrows-left-right"></i>
                                </span>
                            @endif
                        @endif

                        @if ($booking->is_revoked)
                            <span title="Révoquée"
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-red-50 text-red-600 border border-red-200">
                                <i class="fas fa-ban"></i>
                            </span>
                        @endif

                    </div>
                </div>
                <div class="px-6 py-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                        {{-- Client --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Client</label>
                            <div class="mt-1 flex items-center">
                                <img src="{{ 'https://ui-avatars.com/api/?name=' . urlencode($booking->client_name ?? 'Client') }}"
                                    class="w-10 h-10 rounded-full mr-3">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ $booking->client_name ?? 'N/A' }}</p>
                                    <p class="text-sm text-gray-500">{{ $booking->user->email ?? '' }}</p>
                                    <p class="text-sm text-gray-500">{{ $booking->phone }}</p>
                                </div>
                            </div>
                        </div>

                        {{-- Agent --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Agent</label>
                            @if ($booking->driver)
                                <div class="mt-1 flex items-center">
                                    <img src="{{ 'https://ui-avatars.com/api/?name=' . urlencode($booking->driver->user->name ?? 'Agent') }}"
                                        class="w-10 h-10 rounded-full mr-3">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">
                                            {{ $booking->driver->user->name ?? 'N/A' }}</p>
                                        <p class="text-sm text-gray-500">{{ $booking->driver->user->email ?? '' }}</p>
                                    </div>
                                </div>
                            @else
                                <p class="mt-1 text-sm text-gray-500">Aucun Agent assigné</p>
                            @endif
                        </div>

                        {{-- Agent abonnement lié --}}
                        @if ($booking->is_recurring && $booking->subscriptionDriver)
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">Agent lié à l'abonnement</label>
                                <div class="mt-1 flex items-center gap-3">
                                    <img src="{{ 'https://ui-avatars.com/api/?name=' . urlencode($booking->subscriptionDriver->user->name ?? 'Agent') }}"
                                        class="w-8 h-8 rounded-full">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">
                                            {{ $booking->subscriptionDriver->user->name }}</p>
                                        <p class="text-xs text-gray-500">Lié à toutes les courses de cet abonnement</p>
                                    </div>
                                </div>
                            </div>
                        @endif

                        {{-- Trajet --}}
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Trajet</label>
                            <div class="mt-1">
                                <p class="text-sm text-gray-900">{{ $booking->from_location ?? 'N/A' }}</p>
                                <p class="text-sm text-gray-500"><i class="fas fa-arrow-right"></i>
                                    {{ $booking->to_location ?? 'N/A' }}</p>
                            </div>
                        </div>

                        {{-- Distance --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Distance estimée</label>
                            <p class="mt-1 text-sm text-gray-900">{{ $booking->distance ?? 'N/A' }} km</p>
                        </div>

                        {{-- Date départ --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Date et heure de départ</label>
                            <p class="mt-1 text-sm text-gray-900">
                                {{ formatDateTimeFr($booking->pickup_date_time) }}</p>
                            </p>
                        </div>

                        {{-- Heure de retour si aller-retour --}}
                        @if ($booking->round_trip && $booking->return_time)
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Heure de retour</label>
                                <p class="mt-1 text-sm text-gray-900">{{ $booking->return_time }}</p>
                            </div>
                        @endif

                        {{-- Prix du trajet --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Prix du trajet </label>
                            <p class="mt-1 text-lg font-semibold text-purple-600">
                                {{ number_format($booking->base_price, 0, ',', ' ') }} FCFA</p>
                        </div>

                        {{-- Prix total si abonnement --}}
                        @if ($booking->days > 1)
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Prix de l'abonnement </label>
                                <p class="mt-1 text-lg font-semibold text-purple-600">
                                    {{ number_format($booking->total_price, 0, ',', ' ') }} FCFA</p>
                            </div>
                        @endif

                        {{-- Commission --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 flex items-center gap-1">
                                Commission
                                @if (!$booking->commission)
                                    <span
                                        class="text-xs font-normal text-amber-500 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded-full ml-1">
                                        Aperçu
                                    </span>
                                @endif
                            </label>
                            <p class="mt-1 text-lg font-semibold text-purple-600">
                                {{ number_format($booking->commission_preview, 0, ',', ' ') }} FCFA
                            </p>
                        </div>

                        {{-- Revenu agent --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 flex items-center gap-1">
                                Revenu agent
                                @if (!$booking->driver_earning)
                                    <span
                                        class="text-xs font-normal text-amber-500 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded-full ml-1">
                                        Aperçu
                                    </span>
                                @endif
                            </label>
                            <p class="mt-1 text-lg font-semibold text-purple-600">
                                {{ number_format($booking->driver_earning_preview, 0, ',', ' ') }} FCFA
                            </p>
                        </div>

                        {{-- Statut --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Statut</label>
                            <span
                                class="mt-1 px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full {{ bookingStatusBadge($booking->status) }}">
                                {{ bookingStatusLabel($booking->status) }}
                            </span>
                        </div>

                        {{-- Révocation --}}
                        @if ($booking->is_revoked)
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Révocation</label>
                                <p class="mt-1 text-sm text-red-500">
                                    <i class="fas fa-ban mr-1"></i>
                                    Course révoquée le {{ formatDateTimeFr($booking->revoked_at) }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Détails supplémentaires -->
            <div class="bg-white rounded-lg shadow-md">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Détails Supplémentaires</h3>
                </div>
                <div class="px-6 py-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Nombre de passagers</label>
                            <p class="mt-1 text-sm text-gray-900">{{ $booking->passengers }}</p>
                        </div>
                        {{-- <div>
                            <label class="block text-sm font-medium text-gray-700">Circuit touristique</label>
                            <p class="mt-1 text-sm text-gray-900">{{ $booking->touristCircuit->name ?? 'N/A' }}</p>
                        </div> --}}
                        {{-- <div>
                            <label class="block text-sm font-medium text-gray-700">Code promo</label>
                            <p class="mt-1 text-sm text-gray-900">{{ $booking->promoCode->code ?? 'Aucun' }}</p>
                        </div> --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Nombre de jours</label>
                            <p class="mt-1 text-sm text-gray-900">{{ $booking->days ?? 1 }}</p>
                        </div>
                        @if ($booking->days > 1)
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Jours de circulation</label>
                                <p class="mt-1 text-sm text-gray-900">
                                    {{ match ($booking->week_days) {
                                        'lun_ven' => 'Lundi → Vendredi',
                                        'lun_sam' => 'Lundi → Samedi',
                                        'lun_dim' => 'Lundi → Dimanche',
                                        default => 'N/A',
                                    } }}
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">Jours restants</label>
                                <p class="mt-1 text-sm text-gray-900">
                                    {{ $booking->remaining_days > 1 ? $booking->remaining_days . ' jour(s)' : 'Dernier jour' }}
                                </p>
                            </div>

                            @if ($booking->is_recurring)
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Date de fin de
                                        l'abonnement</label>
                                    <p class="mt-1 text-sm text-gray-900">
                                        {{ formatDateFr($booking->subscription_end_date) }}</p>
                                </div>
                            @endif

                            @if ($booking->is_subscription_child)
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Abonnement parent</label>
                                    <a href="{{ route('admin.bookings.show', $booking->parent_booking_id) }}"
                                        class="mt-1 inline-flex items-center gap-1 text-sm text-[#286b41] hover:underline">
                                        <i class="fas fa-arrow-up-right-from-square text-xs"></i>
                                        {{ $booking->parentBooking?->booking_number }}
                                    </a>
                                </div>
                            @endif

                            @if ($booking->is_subscription_parent)
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Courses enfants</label>
                                    <div class="mt-1 space-y-1">
                                        @forelse ($booking->childBookings as $child)
                                            <a href="{{ route('admin.bookings.show', $child) }}"
                                                class="flex items-center justify-between text-sm text-gray-700 hover:text-[#286b41] hover:underline">
                                                <span>
                                                    <i class="fas fa-link text-xs text-gray-400 mr-1"></i>
                                                    {{ $child->subscription_label }}
                                                </span>
                                                <span
                                                    class="px-2 py-0.5 rounded-full text-xs {{ bookingStatusBadge($child->status) }}">
                                                    {{ bookingStatusLabel($child->status) }}
                                                </span>
                                            </a>
                                        @empty
                                            <p class="text-sm text-gray-400">Aucune course enfant encore créée</p>
                                        @endforelse
                                    </div>
                                </div>
                            @endif
                        @endif

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Instructions spéciales</label>
                            <p class="mt-1 text-sm text-gray-900">{{ $booking->special_requests ?? 'Aucune' }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions et historique -->
        <div class="space-y-6">
            <!-- Actions rapides -->
            <div class="bg-white rounded-lg shadow-md">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Actions</h3>
                </div>
                <div class="px-6 py-4 space-y-3">
                    {{-- Assigner un agent --}}
                    @if ($canAssign)
                        <button onclick="assignDriver('{{ $booking->id }}')"
                            class="w-full bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 transition">
                            <i class="fas fa-user-plus mr-2"></i> Assigner un Agent
                        </button>
                    @endif
                    {{-- Transférer l'abonnement --}}
                    @if ($canTransfer)
                        <button onclick="openTransferModal()"
                            class="w-full bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition">
                            <i class="fas fa-exchange-alt mr-2"></i> Transférer l'abonnement
                        </button>
                    @endif
                    {{-- Retirer l'agent --}}
                    @if ($canRemoveDriver)
                        <button onclick="confirmRemoveDriver('{{ $booking->id }}')"
                            class="w-full bg-orange-500 text-white px-4 py-2 rounded-lg hover:bg-orange-600 transition">
                            <i class="fas fa-user-times mr-2"></i> Retirer le Agent
                        </button>
                    @endif
                    {{-- Annuler --}}
                    @if ($canCancel)
                        <button
                            onclick="openCancelModal('{{ $booking->id }}', '{{ $isParent ? 'subscription' : 'booking' }}')"
                            class="w-full text-red-600 border border-red-600 px-4 py-2 rounded-lg hover:bg-red-50 transition">
                            <i class="fas fa-times mr-2"></i>
                            {{ $isParent ? "Annuler l'abonnement" : 'Annuler la course' }}
                        </button>
                    @endif
                    {{-- Supprimer --}}
                    @if ($canDelete)
                        <button onclick="openDeleteModal('{{ $booking->id }}')"
                            class="w-full bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 transition">
                            <i class="fas fa-trash mr-2"></i> Supprimer
                        </button>
                    @endif
                </div>
            </div>

            <!-- Historique -->
            <div class="bg-white rounded-lg shadow-md">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Historique</h3>
                </div>
                <div class="px-6 py-4">
                    <div class="space-y-3">
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <i class="fas fa-calendar-plus text-purple-500"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-900">Réservation créée</p>
                                <p class="text-sm text-gray-500">{{ formatDateTimeFr($booking->created_at) }}</p>
                            </div>
                        </div>
                        @if ($booking->updated_at != $booking->created_at)
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-edit text-blue-500"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">Dernière modification</p>
                                    <p class="text-sm text-gray-500">{{ formatDateTimeFr($booking->updated_at) }}</p>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal pour assigner un Agent -->
    @include('inc.modals.bookings.assign-driver')

    <!-- Modal de transfert d'abonnement -->
    @if ($canTransfer)
        @include('inc.modals.bookings.transfer-subscription')
    @endif

    <!-- Modal de retrait -->
    @include('inc.modals.bookings.remove-driver')

    <!-- Modal d'annulation -->
    @include('inc.modals.bookings.cancel')

    <!-- Modal de suppression -->
    @include('inc.modals.bookings.delete')

    @push('scripts')
        <script>
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                    'Accept': 'application/json'
                }
            });

            function assignDriver(bookingId) {
                $('#currentBookingId').val(bookingId);
                $('#assignDriverModal').removeClass('hidden').addClass('flex');

                const $select = $('#driverSelect');
                $select.html('<option value="">Chargement...</option>');

                $.ajax({
                    url: '/admin/drivers',
                    method: 'GET',
                    data: {
                        available: 1
                    },
                    dataType: 'json',
                    success: function(data) {
                        $select.html('<option value="">Sélectionnez un Agent</option>');
                        if (data && data.length > 0) {
                            data.forEach(function(user) {
                                const driverId = user.driver ? user.driver.id : user.id;
                                const driverName = user.name ?? 'Agent';
                                $select.append($('<option>', {
                                    value: driverId,
                                    text: driverName
                                }));
                            });
                        } else {
                            $select.html('<option value="">Aucun Agent disponible</option>');
                        }
                    },
                    error: function(xhr) {
                        console.error('Erreur chargement Agents:', xhr.status, xhr.responseText);
                        $select.html('<option value="">Erreur de chargement</option>');
                    }
                });
            }

            function openTransferModal() {
                $('#transferSubscriptionModal').removeClass('hidden').addClass('flex');
            }

            function closeTransferModal() {
                $('#transferSubscriptionModal').addClass('hidden').removeClass('flex');
            }

            function confirmTransfer() {
                const driverId = $('#transferDriverSelect').val();

                if (!driverId) {
                    showAlert('error', 'Veuillez sélectionner le nouvel agent');
                    return;
                }

                $.ajax({
                    url: `/admin/bookings/{{ $booking->id }}/transfer-subscription`,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        driver_id: driverId
                    }),
                    success: function(data) {
                        closeTransferModal();
                        showAlert('success', data.message);
                        setTimeout(() => location.reload(), 2000);
                    },
                    error: function(xhr) {
                        showAlert('error', xhr.responseJSON?.message ?? 'Le transfert a échoué.');
                    }
                });
            }

            function closeModal() {
                $('#assignDriverModal').addClass('hidden').removeClass('flex');
                $('#removeDriverModal').addClass('hidden').removeClass('flex');
                $('#removeBookingId').val('');
            }

            function confirmAssign() {
                const bookingId = $('#currentBookingId').val();
                const driverId = $('#driverSelect').val();

                if (!driverId) {
                    showAlert('error', 'Veuillez sélectionner un Agent');
                    return;
                }

                $.ajax({
                    url: `/admin/bookings/${bookingId}/assign-driver`,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        driver_id: driverId
                    }),
                    success: function(data) {
                        if (data && data.success) {
                            closeModal();
                            showAlert('success', data.message);
                            setTimeout(() => location.reload(), 2000);
                        } else {
                            showAlert('error', "Erreur lors de l'assignation: " + (data.message ||
                                'Erreur inconnue'));
                        }
                    },
                    error: function(xhr) {
                        console.error('Erreur assignation:', xhr.status, xhr.responseText);
                        showAlert('error', xhr.responseJSON.message);
                    }
                });
            }

            function confirmRemoveDriver(bookingId) {
                $('#removeBookingId').val(bookingId);
                $('#removeDriverModal').removeClass('hidden').addClass('flex');
            }

            function removeDriver() {
                const bookingId = $('#removeBookingId').val();

                $.ajax({
                    url: `/admin/bookings/${bookingId}/remove-driver`,
                    method: 'POST',
                    success: function(data) {
                        if (data && data.success) {
                            closeModal();
                            showAlert('success', data.message);
                            setTimeout(() => location.reload(), 2000);
                        } else {
                            showAlert('error', "Erreur lors du retrait: " + (data.message || 'Erreur inconnue'));
                        }
                    },
                    error: function(xhr) {
                        console.error('Erreur retrait:', xhr.status, xhr.responseText);
                        showAlert('error', xhr.responseJSON.message);
                    }
                });
            }

            function updateStatus(bookingId, status) {
                if (!confirm('Êtes-vous sûr de vouloir changer le statut ?')) return;

                $.ajax({
                    url: `/admin/bookings/${bookingId}/update-status`,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        status: status
                    }),
                    success: function(data) {
                        if (data && data.success) {
                            showAlert('success', 'Statut mis à jour avec succès');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showAlert('error', "Erreur lors de la mise à jour: " + (data.message ||
                                'Erreur inconnue'));
                        }
                    },
                    error: function(xhr) {
                        console.error('Erreur mise à jour statut:', xhr.status, xhr.responseText);
                        showAlert('error', "Erreur lors de la mise à jour du statut");
                    }
                });
            }

            function openCancelModal(bookingId, type) {
                const title = document.getElementById('cancelModalTitle');
                const desc = document.getElementById('cancelModalDesc');
                const warning = document.getElementById('cancelSubscriptionWarning');

                if (type === 'subscription') {
                    title.textContent = "Annuler l'abonnement";
                    desc.textContent = "Le client ne souhaite plus continuer cet abonnement.";
                    warning.classList.remove('hidden');
                } else {
                    title.textContent = "Annuler la course";
                    desc.textContent = "Êtes-vous sûr de vouloir annuler cette course ? Cette action est irréversible.";
                    warning.classList.add('hidden');
                }

                $('#cancelForm').attr('action', `/admin/bookings/${bookingId}/update-status`);
                $('#cancelModal').removeClass('hidden').addClass('flex');
            }

            function closeCancelModal() {
                $('#cancelModal').addClass('hidden').removeClass('flex');
                $('#cancelForm')[0].reset();
                document.getElementById('cancelSubscriptionWarning').classList.add('hidden');
            }

            // Gestionnaire pour la soumission du formulaire d'annulation
            $('#cancelForm').on('submit', function(e) {
                e.preventDefault();

                const formData = new FormData(this);

                $.ajax({
                    url: $(this).attr('action'),
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(data) {
                        if (data && data.success) {
                            closeCancelModal();
                            showAlert('success', 'Course annulée avec succès');
                            setTimeout(() => location.reload(), 2000);
                        } else {
                            showAlert('error', "Erreur lors de l'annulation: " + (data.message ||
                                'Erreur inconnue'));
                        }
                    },
                    error: function(xhr) {
                        console.error('Erreur annulation:', xhr.status, xhr.responseText);
                        showAlert('error', xhr.responseJSON
                            .message);
                    }
                });
            });

            function openDeleteModal(bookingId) {
                $('#deleteForm').attr('action', `/admin/bookings/${bookingId}`);
                $('#deleteModal').removeClass('hidden').addClass('flex');
            }

            function closeDeleteModal() {
                $('#deleteModal').addClass('hidden').removeClass('flex');
                $('#deleteForm')[0].reset();
            }
        </script>
    @endpush
@endsection
