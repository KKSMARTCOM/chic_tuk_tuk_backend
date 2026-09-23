@extends('layouts.app')

@php
    $driver = $driverData;
    $driverProfile = $driver->driver;
    $activeContract = $driverProfile?->activeDriverContract?->load(['vehicle.owner', 'vehicleContract']);
    $currentVehicle = $activeContract?->vehicle;
    $currentOwner = $currentVehicle?->owner;

    // Contrat modifiable = actif + aucune pause ni paiement liés
    $contractEditable =
        $activeContract &&
        $activeContract->leaveRequests()->count() === 0 &&
        $activeContract->payments()->count() === 0;
@endphp

@section('content')

    {{-- Header --}}
    <div class="bg-white rounded-lg shadow-md mb-6">
        <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg md:text-2xl font-bold text-gray-800">{{ $driver->name }}</h1>
                <p class="text-sm text-gray-500 mt-0.5 flex items-center gap-2">
                    <span
                        class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $driver->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                        {{ $driver->is_active ? 'Actif' : 'Inactif' }}
                    </span>
                    <span
                        class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $driverProfile?->is_available ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600' }}">
                        {{ $driverProfile?->is_available ? 'Disponible' : 'Indisponible' }}
                    </span>
                    @if ($driverProfile?->agent_code)
                        <span class="text-gray-400 text-xs">Code : {{ $driverProfile->agent_code }}</span>
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.drivers.edit', $driver) }}"
                    class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition text-sm font-semibold">
                    <i class="fas fa-edit mr-1"></i> Modifier
                </a>
                <a href="{{ route('admin.drivers.index') }}"
                    class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition text-sm font-semibold">
                    <i class="fas fa-arrow-left mr-1"></i> Retour
                </a>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- ── Colonne principale ── --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Stats courses --}}
            <div class="bg-white rounded-lg shadow-md">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-base font-bold text-gray-800">Statistiques des courses</h3>
                </div>
                <div class="px-6 py-5">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                        @foreach ([['label' => 'Total', 'value' => $bookingStats['total'], 'color' => 'blue'], ['label' => 'Terminées', 'value' => $bookingStats['completed'], 'color' => 'green'], ['label' => 'Confirmées', 'value' => $bookingStats['confirmed'], 'color' => 'yellow'], ['label' => 'Annulées', 'value' => $bookingStats['cancelled'], 'color' => 'red']] as $s)
                            <div class="text-center p-3 bg-{{ $s['color'] }}-50 rounded-lg">
                                <div class="text-2xl font-bold text-{{ $s['color'] }}-600">{{ $s['value'] }}</div>
                                <div class="text-xs text-gray-600">{{ $s['label'] }}</div>
                            </div>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="text-center p-3 bg-purple-50 rounded-lg">
                            <div class="text-xl font-bold text-purple-600">
                                {{ number_format($bookingStats['total_minutes']) }}</div>
                            <div class="text-xs text-gray-600">Min. conduites</div>
                        </div>
                        <div class="text-center p-3 bg-indigo-50 rounded-lg">
                            <div class="text-xl font-bold text-indigo-600">
                                {{ number_format($bookingStats['average_rating'], 1) }}</div>
                            <div class="text-xs text-gray-600">Note moyenne</div>
                        </div>
                        <div class="text-center p-3 bg-orange-50 rounded-lg">
                            <div class="text-xl font-bold text-orange-600">{{ $bookingStats['in_progress'] }}</div>
                            <div class="text-xs text-gray-600">En cours</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Revenus abonnements --}}
            @if ($subscriptionRevenue['subscriptions']->isNotEmpty())
                <div class="bg-white rounded-lg shadow-md">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-base font-bold text-gray-800">Revenus abonnements</h3>
                    </div>
                    <div class="px-6 py-5">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
                            <div class="text-center p-4 bg-yellow-50 rounded-lg">
                                <div class="text-xl font-bold text-yellow-600">
                                    {{ number_format($subscriptionRevenue['total_due'], 0, ',', ' ') }}</div>
                                <div class="text-xs text-gray-600">Total dû (FCFA)</div>
                            </div>
                            <div class="text-center p-4 bg-green-50 rounded-lg">
                                <div class="text-xl font-bold text-green-600">
                                    {{ number_format($subscriptionRevenue['total_paid'], 0, ',', ' ') }}</div>
                                <div class="text-xs text-gray-600">Total payé (FCFA)</div>
                            </div>
                            <div class="text-center p-4 bg-orange-50 rounded-lg">
                                <div class="text-xl font-bold text-orange-600">
                                    {{ number_format($subscriptionRevenue['balance_due'], 0, ',', ' ') }}</div>
                                <div class="text-xs text-gray-600">Solde dû (FCFA)</div>
                            </div>
                        </div>
                        <div class="space-y-2">
                            @foreach ($subscriptionRevenue['subscriptions'] as $subscription)
                                <div
                                    class="flex items-center justify-between p-3 border border-gray-100 rounded-lg">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-800">
                                            Abonn. {{ $subscription['booking_number'] }}</p>
                                        <p class="text-xs text-gray-400">
                                            {{ $subscription['bookings_count'] }}
                                            {{ Str::plural('course', $subscription['bookings_count']) }} effectuée(s)
                                        </p>
                                    </div>
                                    <p class="text-sm font-semibold text-gray-700">
                                        {{ number_format($subscription['amount'], 0, ',', ' ') }} FCFA</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            {{-- Stats commissions --}}
            <div class="bg-white rounded-lg shadow-md">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-base font-bold text-gray-800">Commissions</h3>
                </div>
                <div class="px-6 py-5">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="text-center p-4 bg-yellow-50 rounded-lg">
                            <div class="text-xl font-bold text-yellow-600">
                                {{ number_format($commissionStats['driver_earning'], 0, ',', ' ') }}</div>
                            <div class="text-xs text-gray-600">Revenu total agent (FCFA)</div>
                        </div>
                        <div class="text-center p-4 bg-red-50 rounded-lg">
                            <div class="text-xl font-bold text-red-600">
                                {{ number_format($commissionStats['unpaid_revenue'], 0, ',', ' ') }}</div>
                            <div class="text-xs text-gray-600">Commission due (FCFA)</div>
                        </div>
                        <div class="text-center p-4 bg-green-50 rounded-lg">
                            <div class="text-xl font-bold text-green-600">
                                {{ number_format($commissionStats['paid_revenue'], 0, ',', ' ') }}</div>
                            <div class="text-xs text-gray-600">Commission payée (FCFA)</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Contrat véhicule actif --}}
            <div class="bg-white rounded-lg shadow-md">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-base font-bold text-gray-800">Contrat véhicule actif</h3>
                    <div class="flex gap-2">
                        @if ($activeContract)
                            @if ($contractEditable)
                                <button onclick="openEditContractModal()"
                                    class="text-xs px-3 py-1.5 bg-blue-100 text-blue-700 hover:bg-blue-200 rounded-lg font-semibold transition">
                                    <i class="fas fa-edit mr-1"></i> Modifier
                                </button>
                            @endif
                            <button onclick="openEndContractModal()"
                                class="text-xs px-3 py-1.5 bg-red-100 text-red-700 hover:bg-red-200 rounded-lg font-semibold transition">
                                <i class="fas fa-stop mr-1"></i> Terminer
                            </button>
                        @else
                            <a href="{{ route('admin.drivers.edit', $driver) }}"
                                class="text-xs px-3 py-1.5 bg-purple-100 text-purple-700 hover:bg-purple-200 rounded-lg font-semibold transition">
                                <i class="fas fa-plus mr-1"></i> Assigner un véhicule
                            </a>
                        @endif
                    </div>
                </div>
                <div class="px-6 py-5">
                    @if ($activeContract && $currentVehicle)
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                            {{-- Véhicule --}}
                            <div class="bg-gray-50 rounded-lg p-4">
                                <p class="text-xs text-gray-500 mb-1">Véhicule</p>
                                <p class="font-bold text-gray-800">{{ $currentVehicle->vehicle_number }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ ucfirst($currentVehicle->vehicle_type) }}{{ $currentVehicle->color ? ' · ' . $currentVehicle->color : '' }}
                                </p>
                                <a href="{{ route('admin.vehicles.show', $currentVehicle) }}"
                                    class="text-xs text-blue-600 hover:underline mt-1 block">Voir la fiche →</a>
                            </div>
                            {{-- Propriétaire --}}
                            <div class="bg-gray-50 rounded-lg p-4">
                                <p class="text-xs text-gray-500 mb-1">Propriétaire</p>
                                <p class="font-bold text-gray-800">{{ $currentOwner?->name ?? '—' }}</p>
                                <p class="text-xs text-gray-500">{{ $currentOwner?->phone }}</p>
                            </div>
                            {{-- Contrat agent --}}
                            <div class="bg-gray-50 rounded-lg p-4">
                                <p class="text-xs text-gray-500 mb-1">Durée contrat agent</p>
                                <p class="font-bold text-gray-800">{{ $activeContract->contract_months }} mois</p>
                                <p class="text-xs text-gray-500">Depuis le
                                    {{ $activeContract->start_date->format('d/m/Y') }}</p>
                                <p class="text-xs text-gray-400">{{ $activeContract->months_elapsed }} mois écoulés</p>
                            </div>
                        </div>

                        {{-- Pauses --}}
                        {{-- @php
                            $usedLeave = $activeContract->used_leave_days;
                            $accruedLeave = $activeContract->accrued_leave_days;
                            $surplus = max(0, $usedLeave - $accruedLeave);
                        @endphp
                        <div class="mt-4 grid grid-cols-3 gap-3">
                            <div class="text-center p-3 bg-blue-50 rounded-lg">
                                <div class="text-lg font-bold text-blue-600">{{ $accruedLeave }}</div>
                                <div class="text-xs text-gray-500">Pauses acquises</div>
                            </div>
                            <div class="text-center p-3 bg-emerald-50 rounded-lg">
                                <div class="text-lg font-bold text-emerald-600">{{ $usedLeave }}</div>
                                <div class="text-xs text-gray-500">Pauses utilisées</div>
                            </div>
                            <div class="text-center p-3 {{ $surplus > 0 ? 'bg-orange-50' : 'bg-gray-50' }} rounded-lg">
                                <div class="text-lg font-bold {{ $surplus > 0 ? 'text-orange-600' : 'text-gray-600' }}">
                                    {{ $surplus > 0 ? '+' . $surplus . ' surplus' : max(0, $accruedLeave - $usedLeave) . ' dispo' }}
                                </div>
                                <div class="text-xs text-gray-500">Solde pauses</div>
                            </div>
                        </div> --}}

                        @if (!$contractEditable)
                            <div
                                class="mt-3 bg-amber-50 border border-amber-200 rounded-lg px-4 py-2.5 text-xs text-amber-700">
                                <i class="fas fa-lock mr-1"></i>
                                Ce contrat ne peut plus être modifié directement car il a des pauses ou paiements liés.
                                Pour changer de véhicule, terminez ce contrat et créez-en un nouveau.
                            </div>
                        @endif
                    @else
                        <div class="py-8 text-center text-gray-400">
                            <i class="fas fa-file-contract text-4xl mb-3"></i>
                            <p class="text-sm">Aucun contrat véhicule actif.</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Dernières courses --}}
            <div class="bg-white rounded-lg shadow-md">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-base font-bold text-gray-800">Dernières courses</h3>
                    <a href="{{ route('admin.bookings.index', ['driver' => $driver->id]) }}"
                        class="text-xs text-purple-600 hover:underline">Voir tout →</a>
                </div>
                <div class="px-6 py-4">
                    @if ($driverProfile && $driverProfile->bookings->count() > 0)
                        <div class="space-y-3">
                            @foreach ($driverProfile->bookings->take(5) as $booking)
                                <div
                                    class="flex items-start justify-between p-3 border border-gray-100 rounded-lg hover:bg-gray-50 transition">
                                    <div class="flex items-start gap-3">
                                        <span
                                            class="px-2 py-0.5 text-xs rounded-full font-semibold {{ bookingStatusBadge($booking->status) }} flex-shrink-0 mt-0.5">
                                            {{ bookingStatusLabel($booking->status) }}
                                        </span>
                                        <div>
                                            <p class="text-sm text-gray-800">
                                                {{ Str::limit($booking->from_location, 25) }}
                                                → {{ Str::limit($booking->to_location, 25) }}
                                            </p>
                                            <p class="text-xs text-gray-400">
                                                {{ formatDateTimeFr($booking->pickup_date_time) }}</p>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <p class="text-xs font-semibold text-gray-700">
                                            {{ number_format($booking->driver_earning, 0, ',', ' ') }} FCFA</p>
                                        <p class="text-xs text-gray-400">com.
                                            {{ number_format($booking->commission, 0, ',', ' ') }} FCFA</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-400 text-center py-6">Aucune course trouvée.</p>
                    @endif
                </div>
            </div>

            {{-- Informations personnelles --}}
            <div class="bg-white rounded-lg shadow-md">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-base font-bold text-gray-800">Informations personnelles</h3>
                </div>
                <div class="px-6 py-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach ([['label' => 'Nom complet', 'value' => $driver->name], ['label' => 'Email', 'value' => $driver->email ?? 'N/A'], ['label' => 'Téléphone', 'value' => $driver->phone], ['label' => 'Adresse', 'value' => $driver->adresse ?? 'N/A'], ['label' => 'Catégorie de permis', 'value' => $driverProfile?->license_number ?? 'N/A'], ['label' => 'Membre depuis', 'value' => formatDateFr($driver->created_at)]] as $info)
                        <div>
                            <p class="text-xs text-gray-500">{{ $info['label'] }}</p>
                            <p class="text-sm font-semibold text-gray-800 mt-0.5">{{ $info['value'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

        </div>

        {{-- ── Colonne droite ── --}}
        <div class="space-y-6">

            {{-- Actions --}}
            <div class="bg-white rounded-lg shadow-md">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-base font-bold text-gray-800">Actions</h3>
                </div>
                <div class="px-6 py-5 space-y-2">
                    <button onclick="openLeaveModal()"
                        class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-sm font-semibold">
                        <i class="fas fa-calendar-minus mr-2"></i> Ajouter une pause
                    </button>
                    <button onclick="openPaymentModal()"
                        class="w-full px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition text-sm font-semibold">
                        <i class="fas fa-money-bill mr-2"></i> Enregistrer le pymt d'une commission
                    </button>
                    @if ($subscriptionRevenue['subscriptions']->isNotEmpty())
                        <button onclick="openSubscriptionRevenuePaymentModal()"
                            class="w-full px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition text-sm font-semibold">
                            <i class="fas fa-hand-holding-usd mr-2"></i> Enregistrer le pymt de revenus abonnement
                        </button>
                    @endif
                    <a href="{{ route('admin.payments.driver-details', $driverProfile->id) }}"
                        class="block w-full text-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition text-sm font-semibold">
                        <i class="fas fa-chart-line mr-2"></i> Voir les paiements
                    </a>
                    <button
                        onclick="openAvailabilityModal('{{ $driver->id }}', {{ $driverProfile?->is_available ? 'false' : 'true' }}, '{{ $driver->name }}', '{{ $driverProfile?->is_available ? 'indisponible' : 'disponible' }}')"
                        class="w-full px-4 py-2 {{ $driverProfile?->is_available ? 'bg-orange-100 text-orange-700 hover:bg-orange-200' : 'bg-green-100 text-green-700 hover:bg-green-200' }} rounded-lg transition text-sm font-semibold">
                        <i class="fas {{ $driverProfile?->is_available ? 'fa-pause' : 'fa-play' }} mr-2"></i>
                        {{ $driverProfile?->is_available ? 'Marquer indisponible' : 'Marquer disponible' }}
                    </button>
                    <button
                        onclick="openStatusModal('{{ $driver->id }}', {{ $driver->is_active ? 'false' : 'true' }}, '{{ $driver->name }}', '{{ $driver->is_active ? 'désactiver' : 'activer' }}')"
                        class="w-full px-4 py-2 border {{ $driver->is_active ? 'border-red-300 text-red-600 hover:bg-red-50' : 'border-green-300 text-green-600 hover:bg-green-50' }} rounded-lg transition text-sm font-semibold">
                        <i class="fas {{ $driver->is_active ? 'fa-user-times' : 'fa-user-check' }} mr-2"></i>
                        {{ $driver->is_active ? 'Désactiver le compte' : 'Activer le compte' }}
                    </button>
                </div>
            </div>

            {{-- Infos contrat agent --}}
            <div class="bg-white rounded-lg shadow-md">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-base font-bold text-gray-800">Infos contrat</h3>
                </div>
                <div class="px-6 py-5 space-y-3 text-sm">
                    @foreach ([['label' => 'Code Agent', 'value' => $driverProfile?->agent_code ?? 'N/A'], ['label' => 'ID Agent', 'value' => $driverProfile?->agent_id ?? 'N/A']] as $info)
                        <div class="flex justify-between">
                            <span class="text-gray-500">{{ $info['label'] }}</span>
                            <span class="font-semibold text-gray-800">{{ $info['value'] }}</span>
                        </div>
                    @endforeach
                    @if ($activeContract)
                        <div class="flex justify-between">
                            <span class="text-gray-500">Durée contrat</span>
                            <span class="font-semibold text-gray-800">{{ $activeContract->contract_months }} mois</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Début contrat</span>
                            <span
                                class="font-semibold text-gray-800">{{ $activeContract->start_date->format('d/m/Y') }}</span>
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </div>

    {{-- ===== MODALS ===== --}}

    {{-- Modal modifier contrat (si sans historique) --}}
    @include('inc.modals.drivers.edit-contract')

    {{-- Modal terminer contrat --}}
    @include('inc.modals.drivers.end-contract')

    {{-- Modal pause agent --}}
    @include('inc.modals.drivers.leave')

    {{-- Modal paiement --}}
    @include('inc.modals.drivers.payment')

    {{-- Modal paiement revenus abonnement --}}
    @include('inc.modals.drivers.subscription-revenue-payment')

    {{-- Modals confirmation disponibilité --}}
    @include('inc.modals.drivers.availability')

    {{-- Modals confirmation statut --}}
    @include('inc.modals.drivers.statut')

    @push('scripts')
        <script>
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                    'Accept': 'application/json'
                }
            });

            // Contrat
            function openEditContractModal() {
                document.getElementById('editContractModal')?.classList.replace('hidden', 'flex');
            }

            function closeEditContractModal() {
                document.getElementById('editContractModal')?.classList.replace('flex', 'hidden');
            }

            function openEndContractModal() {
                document.getElementById('endContractModal')?.classList.replace('hidden', 'flex');
            }

            function closeEndContractModal() {
                document.getElementById('endContractModal')?.classList.replace('flex', 'hidden');
            }

            // Pause / Paiement
            function openLeaveModal() {
                document.getElementById('leaveModal').classList.replace('hidden', 'flex');
            }

            function closeLeaveModal() {
                document.getElementById('leaveModal').classList.replace('flex', 'hidden');
            }

            function openPaymentModal() {
                document.getElementById('paymentModal').classList.replace('hidden', 'flex');
            }

            function closePaymentModal() {
                document.getElementById('paymentModal').classList.replace('flex', 'hidden');
            }

            function openSubscriptionRevenuePaymentModal() {
                document.getElementById('subscriptionRevenuePaymentModal').classList.replace('hidden', 'flex');
            }

            function closeSubscriptionRevenuePaymentModal() {
                document.getElementById('subscriptionRevenuePaymentModal').classList.replace('flex', 'hidden');
            }

            // Disponibilité
            function openAvailabilityModal(id, status, name, action) {
                document.getElementById('availabilityMessage').textContent = `Marquer ${name} comme ${action} ?`;
                document.getElementById('availabilityDriverId').value = id;
                document.getElementById('availabilityNewStatus').value = status;
                document.getElementById('availabilityModal').classList.replace('hidden', 'flex');
            }

            function closeAvailabilityModal() {
                document.getElementById('availabilityModal').classList.replace('flex', 'hidden');
            }

            function confirmToggleAvailability() {
                const id = document.getElementById('availabilityDriverId').value;
                const status = document.getElementById('availabilityNewStatus').value === 'true' ? 1 : 0;
                $.post(`/admin/drivers/${id}/toggle-availability`, {
                        is_available: status
                    })
                    .done(d => {
                        if (d.success) {
                            closeAvailabilityModal();
                            showAlert('success', d.message);
                            setTimeout(() => location.reload(), 1500);
                        }
                    })
                    .fail(xhr => showAlert('error', xhr.responseJSON?.message ?? 'Erreur'));
            }

            // Statut
            function openStatusModal(id, status, name, action) {
                document.getElementById('statusMessage').textContent =
                    `${action.charAt(0).toUpperCase() + action.slice(1)} le compte de ${name} ?`;
                document.getElementById('statusDriverId').value = id;
                document.getElementById('statusNewStatus').value = status;
                document.getElementById('statusModal').classList.replace('hidden', 'flex');
            }

            function closeStatusModal() {
                document.getElementById('statusModal').classList.replace('flex', 'hidden');
            }

            function confirmToggleStatus() {
                const id = document.getElementById('statusDriverId').value;
                const status = document.getElementById('statusNewStatus').value === 'true' ? 1 : 0;
                $.post(`/admin/drivers/${id}/toggle-status`, {
                        is_active: status
                    })
                    .done(d => {
                        if (d.success) {
                            closeStatusModal();
                            showAlert('success', d.message);
                            setTimeout(() => location.reload(), 1500);
                        }
                    })
                    .fail(xhr => showAlert('error', xhr.responseJSON?.message ?? 'Erreur'));
            }

            // Pauses
            let adminSelectedDates = [];
            const adminMaxDays = {{ $driverProfile?->available_leave_days ?? 0 }};

            function adminAddDate() {
                const input = document.getElementById('adminLeaveDate');
                const date = input.value;
                const err = document.getElementById('adminDateError');
                err.classList.add('hidden');

                if (!date) {
                    err.textContent = 'Sélectionnez une date.';
                    err.classList.remove('hidden');
                    return;
                }
                if (adminSelectedDates.includes(date)) {
                    err.textContent = 'Date déjà ajoutée.';
                    err.classList.remove('hidden');
                    return;
                }

                adminSelectedDates.push(date);
                adminSelectedDates.sort();
                adminRenderDates();
                input.value = '';
            }

            function adminRemoveDate(date) {
                adminSelectedDates = adminSelectedDates.filter(d => d !== date);
                adminRenderDates();
            }

            function adminClearDates() {
                adminSelectedDates = [];
                document.getElementById('adminLeaveDate').value = '';
                adminRenderDates();
            }

            function adminRenderDates() {
                const container = document.getElementById('adminSelectedDates');
                const inputs = document.getElementById('adminDatesInputs');
                const btn = document.getElementById('adminSubmitBtn');

                if (!adminSelectedDates.length) {
                    container.innerHTML = '<p class="text-gray-400 text-sm w-full text-center">Aucune date sélectionnée</p>';
                    inputs.innerHTML = '';
                    btn.disabled = true;
                    return;
                }
                container.innerHTML = adminSelectedDates.map(d => {
                    const label = new Date(d + 'T00:00:00').toLocaleDateString('fr-FR', {
                        weekday: 'short',
                        day: '2-digit',
                        month: '2-digit'
                    });
                    return `<span class="inline-flex items-center bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm">
                ${label}
                <button type="button" onclick="adminRemoveDate('${d}')" class="ml-2 font-bold hover:text-blue-600">✕</button>
            </span>`;
                }).join('');
                inputs.innerHTML = adminSelectedDates.map(d => `<input type="hidden" name="dates[]" value="${d}">`).join('');
                btn.disabled = false;
            }
            document.getElementById('adminLeaveDate')?.addEventListener('keypress', e => {
                if (e.key === 'Enter') {
                    adminAddDate();
                    e.preventDefault();
                }
            });
        </script>
    @endpush
@endsection
