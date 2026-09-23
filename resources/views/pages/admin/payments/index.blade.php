@extends('layouts.app')

@section('content')
    <!-- Header -->
    <div class="bg-white rounded-lg shadow-md mb-8">
        <div class="px-6 py-4 border-b border-gray-200 block md:flex items-center justify-between">
            <div>
                <h1 class="text-lg md:text-2xl font-bold text-gray-800">Gestion du Paiement</h1>
                <p class="text-sm md:text-base text-gray-600">Enregistrez et gérez le paiement des Agents</p>
            </div>
            <div class="mt-4 md:mt-0">
                <a href="{{ route('admin.payments.create') }}"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-nowrap font-semibold py-2 px-4 rounded-lg">
                    <i class="fas fa-plus mr-2"></i> Ajouter
                </a>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Total Payé -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm font-semibold">Commission Total Payée</p>
                    <p class="text-3xl font-bold text-green-600 mt-2">
                        {{ number_format($stats['total_paid'], 0, ',', ' ') }} FCFA
                    </p>
                </div>
                <div class="bg-green-100 rounded-full w-16 h-16 flex justify-center items-center">
                    <i class="fas fa-check-circle text-green-600 text-2xl"></i>
                </div>
            </div>
            <div class="mt-4 text-sm text-green-600">
                <i class="fas fa-info-circle"></i> {{ $stats['payments_count'] }} paiement(s)
            </div>
        </div>

        <!-- Total Dû -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm font-semibold">Commission Total à Payer</p>
                    <p class="text-3xl font-bold text-red-600 mt-2">
                        {{ number_format($stats['total_due'], 0, ',', ' ') }} FCFA
                    </p>
                </div>
                <div class="bg-red-100 rounded-full w-16 h-16 flex justify-center items-center">
                    <i class="fas fa-exclamation-circle text-red-600 text-2xl"></i>
                </div>
            </div>
            <div class="mt-4 text-sm text-red-600">
                <i class="fas fa-arrow-up"></i> Commission due
            </div>
        </div>

        <!-- Solde Dû -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm font-semibold">Commission Restante à Payer</p>
                    <p class="text-3xl font-bold text-orange-600 mt-2">
                        {{ number_format($stats['balance_due'], 0, ',', ' ') }} FCFA
                    </p>
                </div>
                <div class="bg-orange-100 rounded-full w-16 h-16 flex justify-center items-center">
                    <i class="fas fa-balance-scale text-orange-600 text-2xl"></i>
                </div>
            </div>
            <div class="mt-4 text-sm text-orange-600">
                <i class="fas fa-info-circle"></i> Montant encore à payer
            </div>
        </div>

        <!-- Payé Ce Mois -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm font-semibold">Commission Payée Ce Mois</p>
                    <p class="text-3xl font-bold text-blue-600 mt-2">
                        {{ number_format($stats['paid_this_month'], 0, ',', ' ') }} FCFA
                    </p>
                </div>
                <div class="bg-blue-100 rounded-full w-16 h-16 flex justify-center items-center">
                    <i class="fas fa-calendar text-blue-600 text-2xl"></i>
                </div>
            </div>
            <div class="mt-4 text-sm text-blue-600">
                <i class="fas fa-info-circle"></i> Mois actuel
            </div>
        </div>

        <!-- Paiements Validés -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm font-semibold">Paiements Validés</p>
                    <p class="text-3xl font-bold text-emerald-600 mt-2">
                        {{ number_format($stats['validated_payments_amount'], 0, ',', ' ') }} FCFA
                    </p>
                </div>
                <div class="bg-emerald-100 rounded-full w-16 h-16 flex justify-center items-center">
                    <i class="fas fa-check-double text-emerald-600 text-2xl"></i>
                </div>
            </div>
            <div class="mt-4 text-sm text-emerald-600">
                <i class="fas fa-info-circle"></i> {{ $stats['validated_payments_count'] }} paiement(s) validé(s)
            </div>
        </div>

        <!-- Paiements en Attente -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm font-semibold">Paiements en Attente</p>
                    <p class="text-3xl font-bold text-amber-600 mt-2">
                        {{ number_format($stats['pending_payments_amount'], 0, ',', ' ') }} FCFA
                    </p>
                </div>
                <div class="bg-amber-100 rounded-full w-16 h-16 flex justify-center items-center">
                    <i class="fas fa-hourglass-half text-amber-600 text-2xl"></i>
                </div>
            </div>
            <div class="mt-4 text-sm text-amber-600">
                <i class="fas fa-info-circle"></i> {{ $stats['pending_payments_count'] }} paiement(s) en attente
            </div>
        </div>

        <!-- Paiements Total -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm font-semibold">Paiements Annulés</p>
                    <p class="text-3xl font-bold text-indigo-600 mt-2">
                        {{ number_format($stats['cancelled_payments_amount'], 0, ',', ' ') }} FCFA
                    </p>
                </div>
                <div class="bg-indigo-100 rounded-full w-16 h-16 flex justify-center items-center">
                    <i class="fas fa-money-bill-wave text-indigo-600 text-2xl"></i>
                </div>
            </div>
            <div class="mt-4 text-sm text-indigo-600">
                <i class="fas fa-info-circle"></i> {{ $stats['cancelled_payments_count'] }} paiement(s) annulé(s)
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-md mb-8 p-6">
        <form method="GET" action="{{ route('admin.payments.index') }}" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
                <label class="block text-gray-700 text-sm font-semibold mb-2">Agent</label>
                <select name="driver_id"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Tous les agents --</option>
                    @foreach ($drivers as $driver)
                        <option value="{{ $driver->id }}" {{ request('driver_id') == $driver->id ? 'selected' : '' }}>
                            {{ $driver->user?->name ?? 'N/A' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-gray-700 text-sm font-semibold mb-2">Statut</label>
                <select name="status"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Tous --</option>
                    <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>En attente</option>
                    <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>
                        Validé</option>
                    <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Annulé</option>
                </select>
            </div>

            <div>
                <label class="block text-gray-700 text-sm font-semibold mb-2">Type de Paiement</label>
                <select name="payment_type"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Tous --</option>
                    <option value="commission" {{ request('payment_type') == 'commission' ? 'selected' : '' }}>
                        Commission</option>
                    <option value="contract" {{ request('payment_type') == 'contract' ? 'selected' : '' }}>Contractuel
                    </option>
                    <option value="subscription_revenue"
                        {{ request('payment_type') == 'subscription_revenue' ? 'selected' : '' }}>
                        Revenus abonnement</option>
                </select>
            </div>

            <div>
                <label class="block text-gray-700 text-sm font-semibold mb-2">Recherche</label>
                <input type="text" name="search" placeholder="Nom ou Référence..."
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    value="{{ request('search') }}">
            </div>

            <div>
                <label class="block text-gray-700 text-sm font-semibold mb-2">Du</label>
                <input type="date" name="date_from"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    value="{{ request('date_from') }}">
            </div>

            <div>
                <label class="block text-gray-700 text-sm font-semibold mb-2">Au</label>
                <input type="date" name="date_to"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    value="{{ request('date_to') }}">
            </div>

            <div class="flex items-end">
                <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg">
                    <i class="fas fa-search mr-2"></i> Filtrer
                </button>
            </div>

            <div class="flex items-end">
                <a href="{{ route('admin.payments.index') }}"
                    class="w-full bg-gray-400 hover:bg-gray-500 text-white font-semibold py-2 px-4 rounded-lg text-center">
                    <i class="fas fa-redo mr-2"></i> Réinitialiser
                </a>
            </div>
        </form>
    </div>

    <!-- Payments Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        @if ($payments && $payments->count() > 0)
            <div class="overflow-x-auto p-4">
                <table class="min-w-full divide-y divide-gray-200 display" id="datatable1">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Agent
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Montant
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Type
                                de Paiement</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Type de contrat</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Statut</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Date du paiement
                            </th>
                            {{-- <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Référence</th> --}}
                            <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($payments as $payment)
                            <tr class="border-b border-gray-200 hover:bg-gray-50">
                                <td class="px-6 py-4 text-sm text-gray-700">
                                    <div class="font-semibold">{{ $payment->driver->user?->name ?? 'N/A' }}</div>
                                    <div class="text-xs text-gray-500">ID: {{ $payment->driver->agent_id ?? 'N/A' }}</div>
                                </td>
                                <td class="px-6 py-4 text-sm font-semibold text-green-600">
                                    {{ number_format($payment->amount, 0, ',', ' ') }} FCFA
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span
                                        class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold
                                    {{ $payment->payment_type === 'commission' ? 'bg-green-100 text-green-800' : '' }}
                                    {{ $payment->payment_type === 'contract' ? 'bg-blue-100 text-blue-800' : '' }}
                                    {{ $payment->payment_type === 'subscription_revenue' ? 'bg-teal-100 text-teal-800' : '' }}
                                    {{ $payment->payment_type === 'other' ? 'bg-gray-100 text-gray-800' : '' }}
                                ">
                                        {{ $payment->payment_type === 'subscription_revenue' ? 'Revenus abonnement' : ucfirst(str_replace('_', ' ', $payment->payment_type)) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">
                                    {{ ($payment->vehicleContract->contract_months ?? '--') . ' mois' }}
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span
                                        class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold
                                    {{ $payment->status === 'completed' ? 'bg-emerald-100 text-emerald-800' : '' }}
                                    {{ $payment->status === 'pending' ? 'bg-amber-100 text-amber-800' : '' }}
                                    {{ $payment->status === 'cancelled' ? 'bg-red-100 text-red-800' : '' }}
                                ">
                                        @if ($payment->status === 'completed')
                                            Validé
                                        @elseif ($payment->status === 'pending')
                                            En attente
                                        @elseif ($payment->status === 'cancelled')
                                            Annulé
                                        @else
                                            {{ ucfirst($payment->status) }}
                                        @endif
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">
                                    {{ formatDateFr($payment->payment_date) }}
                                </td>
                                {{-- <td class="px-6 py-4 text-sm text-gray-700">
                                    {{ $payment->reference_number ?? '--' }}
                                </td> --}}
                                <td class="px-6 py-4 text-left text-sm">
                                    <a href="{{ route('admin.payments.show', $payment) }}"
                                        class="text-blue-600 hover:text-blue-800 mr-3">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    @if ($payment->status === 'pending')
                                        <a href="{{ route('admin.payments.edit', $payment) }}"
                                            class="text-green-600 hover:text-green-800 mr-3">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" class="text-emerald-600 hover:text-emerald-800 mr-3"
                                            title="Valider le paiement"
                                            onclick="openPaymentModal('validateModal', '{{ $payment->id }}', '{{ addslashes($payment->driver->user?->name ?? 'N/A') }}', '{{ number_format($payment->amount, 0, ',', ' ') }} FCFA')">
                                            <i class="fas fa-check-circle"></i>
                                        </button>
                                        <button type="button" class="text-red-600 hover:text-red-800"
                                            title="Annuler le paiement"
                                            onclick="openPaymentModal('cancelModal', '{{ $payment->id }}', '{{ addslashes($payment->driver->user?->name ?? 'N/A') }}', '{{ number_format($payment->amount, 0, ',', ' ') }} FCFA')">
                                            <i class="fas fa-times-circle"></i>
                                        </button>
                                    @endif
                                    {{-- <form action="{{ route('admin.payments.generate', $payment) }}" method="POST"
                                        class="inline-block" onclick="return confirm('Êtes-vous sûr ?')">
                                        @csrf
                                        <button type="submit" class="text-red-600 hover:text-red-800">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form> --}}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                    Aucun paiement enregistré
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="px-6 py-12 text-center">
                <p class="px-6 py-4 text-center text-gray-500">Aucun paiement enrégistré</p>
                <i class="fas fa-inbox text-4xl text-gray-400 mb-4"></i>
            </div>
        @endif
    </div>

    <!-- Modal: Valider Paiement -->
    <div id="validateModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-md mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-800">
                    <i class="fas fa-check-circle text-emerald-600 mr-2"></i> Valider le paiement
                </h3>
                <button type="button" onclick="closePaymentModal('validateModal')"
                    class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="validateForm" method="POST" action="">
                @csrf
                @method('PATCH')
                <div class="px-6 py-4">
                    <p class="text-gray-700">
                        Confirmez-vous la validation du paiement de
                        <span id="validateAmount" class="font-semibold"></span>
                        pour <span id="validateName" class="font-semibold"></span> ?
                    </p>
                    <p class="text-sm text-gray-500 mt-2">Cette action marquera le paiement comme validé.</p>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                    <button type="button" onclick="closePaymentModal('validateModal')"
                        class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-2 px-4 rounded-lg">
                        Annuler
                    </button>
                    <button type="submit"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 px-4 rounded-lg">
                        <i class="fas fa-check mr-2"></i> Valider
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Annuler Paiement -->
    <div id="cancelModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-md mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-800">
                    <i class="fas fa-times-circle text-red-600 mr-2"></i> Annuler le paiement
                </h3>
                <button type="button" onclick="closePaymentModal('cancelModal')"
                    class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="cancelForm" method="POST" action="">
                @csrf
                @method('PATCH')
                <div class="px-6 py-4">
                    <p class="text-gray-700">
                        Confirmez-vous l'annulation du paiement de
                        <span id="cancelAmount" class="font-semibold"></span>
                        pour <span id="cancelName" class="font-semibold"></span> ?
                    </p>
                    <p class="text-sm text-red-500 mt-2">Cette action est irréversible.</p>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                    <button type="button" onclick="closePaymentModal('cancelModal')"
                        class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-2 px-4 rounded-lg">
                        Retour
                    </button>
                    <button type="submit"
                        class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg">
                        <i class="fas fa-ban mr-2"></i> Annuler le paiement
                    </button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
        <script>
            $("#datatable1").DataTable({
                order: [
                    [3, "desc"]
                ],
                columnDefs: [{
                    targets: 3,
                    searchable: false,
                }, ],
                language: {
                    processing: "Traitement en cours...",
                    search: "Rechercher : ",
                    lengthMenu: "Afficher _MENU_ éléments",
                    info: "Affichage de _START_ à _END_ sur _TOTAL_ ",
                    infoEmpty: "Affichage de 0 à 0 sur 0",
                    infoFiltered: "(filtré de _MAX_ éléments au total)",
                    loadingRecords: "Chargement en cours...",
                    zeroRecords: "Aucun élément à afficher",
                    emptyTable: "Aucune donnée disponible dans le tableau",
                },
                // Callback pour appliquer select2 après init
                initComplete: function() {
                    if (typeof $.fn.select2 !== "undefined") {
                        $(".dataTables_length select").select2({
                            minimumResultsForSearch: Infinity,
                        });
                    }
                },
            })

            function openPaymentModal(modalId, paymentId, driverName, amount) {
                const modal = document.getElementById(modalId);
                const prefix = modalId === 'validateModal' ? 'validate' : 'cancel';

                document.getElementById(prefix + 'Name').textContent = driverName;
                document.getElementById(prefix + 'Amount').textContent = amount;

                const form = document.getElementById(prefix + 'Form');
                const routeName = prefix === 'validate' ? 'admin.payments.validate' : 'admin.payments.cancel';
                form.action = `{{ url('admin/payments') }}/${paymentId}/${prefix === 'validate' ? 'validated' : 'cancelled'}`;

                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }

            function closePaymentModal(modalId) {
                const modal = document.getElementById(modalId);
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
        </script>
    @endpush
@endsection
