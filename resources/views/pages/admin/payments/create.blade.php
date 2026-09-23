@extends('layouts.app')

@section('content')
    <!-- Header -->
    <div class="bg-white rounded-lg shadow-md mb-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <div>
                <h1 class="text-lg md:text-2xl font-bold text-gray-800">Enregistrer un Paiement</h1>
                <p class="text-sm md:text-base text-gray-600">Ajouter un nouveau paiement d'un Agent</p>
            </div>
            <div class="flex space-x-3">
                <a href="{{ route('admin.payments.index') }}"
                    class="bg-gray-600 text-white text-nowrap px-4 py-2 rounded-lg hover:bg-gray-700 transition">
                    <i class="fas fa-arrow-left mr-2"></i> Retour
                </a>
            </div>
        </div>
    </div>

    <!-- Form -->
    <div class="bg-white rounded-lg shadow-md p-8 mx-auto">
        <form action="{{ route('admin.payments.store') }}" method="POST">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <!-- Agent -->
                <div>
                    <label class="block text-gray-700 text-sm font-semibold mb-2">Agent <span
                            class="text-red-600">*</span></label>
                    <select name="driver_id" id="driver_select" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 @error('driver_id') border-red-500 @enderror">
                        <option value="">-- Sélectionner un agent --</option>
                        @foreach ($drivers as $driver)
                            @php
                                $contractMonths = $driver->activeDriverContract?->contract_months;
                                $vehicleNumber = $driver->currentVehicle?->vehicle_number;
                            @endphp
                            <option value="{{ $driver->id }}" data-months="{{ $contractMonths }}"
                                data-vehicle="{{ $vehicleNumber }}" {{ old('driver_id') == $driver->id ? 'selected' : '' }}>
                                {{ $driver->user?->name ?? 'N/A' }} (Agent ID: {{ $driver->agent_id }})
                            </option>
                        @endforeach
                    </select>

                    <p id="contract_month" class="text-sm text-blue-600 mt-3 hidden">
                        <i class="fas fa-file-contract mr-1"></i>
                        <span id="contract_month_text"></span>
                    </p>

                    @error('driver_id')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Type de Paiement -->
                <div>
                    <label class="block text-gray-700 text-sm font-semibold mb-2">Type de Paiement <span
                            class="text-red-600">*</span></label>
                    <select name="payment_type" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 @error('payment_type') border-red-500 @enderror">
                        <option value="commission" {{ old('payment_type') == 'commission' ? 'selected' : '' }}>Commission
                        </option>
                        <option value="contract" {{ old('payment_type') == 'contract' ? 'selected' : '' }}>Contractuel
                        </option>
                        <option value="subscription_revenue"
                            {{ old('payment_type') == 'subscription_revenue' ? 'selected' : '' }}>Revenus abonnement
                        </option>
                    </select>
                    @error('payment_type')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Montant -->
                <div>
                    <label class="block text-gray-700 text-sm font-semibold mb-2">Montant (FCFA) <span
                            class="text-red-600">*</span></label>
                    <input type="number" name="amount" step="0.01" min="0" required placeholder="0.00"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 @error('amount') border-red-500 @enderror"
                        value="{{ old('amount') }}">
                    @error('amount')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Moyen de Paiement -->
                <div>
                    <label class="block text-gray-700 text-sm font-semibold mb-2">Moyen de Paiement <span
                            class="text-red-600">*</span></label>
                    <select name="payment_method" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 @error('payment_method') border-red-500 @enderror">
                        <option value="">-- Sélectionner --</option>
                        <option value="cash" {{ old('payment_method') == 'cash' ? 'selected' : '' }}>Espèces</option>
                        <option value="bank_transfer" {{ old('payment_method') == 'bank_transfer' ? 'selected' : '' }}>
                            Virement
                            Bancaire</option>
                        <option value="check" {{ old('payment_method') == 'check' ? 'selected' : '' }}>Chèque</option>
                        <option selected value="mobile_money"
                            {{ old('payment_method') == 'mobile_money' ? 'selected' : '' }}>Mobile
                            Money</option>
                        <option value="other" {{ old('payment_method') == 'other' ? 'selected' : '' }}>Autre</option>
                    </select>
                    @error('payment_method')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Date de Paiement -->
                <div>
                    <label class="block text-gray-700 text-sm font-semibold mb-2">Date de Paiement <span
                            class="text-red-600">*</span></label>
                    <input type="date" name="payment_date" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 @error('payment_date') border-red-500 @enderror"
                        value="{{ old('payment_date', now()->format('Y-m-d')) }}">
                    @error('payment_date')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Notes -->
                <div class="col-span-2">
                    <label class="block text-gray-700 text-sm font-semibold mb-2">Notes</label>
                    <textarea name="notes" rows="4" placeholder="Notes supplémentaires..."
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 @error('notes') border-red-500 @enderror">{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Buttons -->
            <div class="flex justify-end gap-4">
                <a href="{{ route('admin.payments.index') }}"
                    class="bg-gray-400 hover:bg-gray-500 text-white font-semibold py-2 px-4 rounded-lg text-center">
                    <i class="fas fa-times mr-2"></i> Annuler
                </a>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg">
                    <i class="fas fa-save mr-2"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>

    @push('scripts')
        <script>
            document.getElementById('driver_select').addEventListener('change', function() {
                const selected = this.options[this.selectedIndex];
                const months = selected.dataset.months;
                const vehicle = selected.dataset.vehicle;
                const info = document.getElementById('contract_month');
                const text = document.getElementById('contract_month_text');

                if (this.value && months) {
                    text.textContent = `Contrat actif de ${months} mois — Véhicule ${vehicle ?? 'N/A'}`;
                    info.classList.remove('hidden');
                } else {
                    info.classList.add('hidden');
                }
            });

            // Préremplir si old() a une valeur
            document.addEventListener('DOMContentLoaded', function() {
                const select = document.getElementById('driver_select');
                if (select.value) select.dispatchEvent(new Event('change'));
            });
        </script>
    @endpush
@endsection
