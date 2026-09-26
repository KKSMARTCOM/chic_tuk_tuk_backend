<div id="contractModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-30">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg mx-4 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-800">Créer un contrat · {{ $vehicle->vehicle_number }}</h3>
            <button onclick="closeContractModal()" class="text-gray-400 hover:text-gray-600"><i
                    class="fas fa-times"></i></button>
        </div>
        <form action="{{ route('admin.vehicle-contracts.store') }}" method="POST" class="space-y-4">
            @csrf
            <input type="hidden" name="vehicle_id" value="{{ $vehicle->id }}">
            <div class="grid grid-cols-2 gap-4">
                {{-- Durée plutôt que mensualité et date de fin (2026-09-26) : sans elle, le
                     contrat naissait sans montant journalier. --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Durée du contrat <span
                            class="text-red-500">*</span></label>
                    <select name="contract_months" required
                        onchange="document.getElementById('contract_modal_total').value = {{ json_encode(\App\Consts\VehicleContractConsts::TOTAL_AMOUNTS) }}[this.value] ?? ''"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                        <option value="">-- Sélectionnez --</option>
                        @foreach (\App\Consts\VehicleContractConsts::TOTAL_AMOUNTS as $months => $amount)
                            <option value="{{ $months }}">{{ $months }} mois</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Montant total (FCFA) <span
                            class="text-red-500">*</span></label>
                    <input type="number" name="total_amount" id="contract_modal_total" min="1" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date de début <span
                            class="text-red-500">*</span></label>
                    <input type="date" name="start_date" required value="{{ date('Y-m-d') }}"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                        placeholder="Conditions particulières..."></textarea>
                </div>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeContractModal()"
                    class="flex-1 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">Annuler</button>
                <button type="submit"
                    class="flex-1 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition font-semibold">
                    <i class="fas fa-save mr-1"></i> Créer le contrat
                </button>
            </div>
        </form>
    </div>
</div>
