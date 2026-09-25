{{-- resources/views/pages/admin/users/edit.blade.php --}}
@extends('layouts.app')

@section('content')

    {{-- Header --}}
    <div class="bg-white rounded-lg shadow-md mb-6">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <div>
                <h1 class="text-lg md:text-2xl font-bold text-gray-800">Modifier l'utilisateur</h1>
                <p class="text-sm text-gray-500">{{ $owner->name }}</p>
            </div>
            <a href="{{ route('admin.owners.index') }}"
                class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition text-sm font-semibold">
                <i class="fas fa-arrow-left mr-1"></i> Retour
            </a>
        </div>
    </div>

    <form action="{{ route('admin.owners.update', $owner) }}" method="POST" class="space-y-6">
        @csrf
        @method('PUT')

        {{-- ── Informations personnelles ── --}}
        <div class="bg-white rounded-lg shadow-md">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-base font-bold text-gray-800">Informations personnelles</h3>
            </div>
            <div class="px-6 py-5 grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom complet <span
                            class="text-red-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name', $owner->name) }}" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 @error('name') border-red-500 @enderror">
                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email', $owner->email) }}"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 @error('email') border-red-500 @enderror">
                    @error('email')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone <span
                            class="text-red-500">*</span></label>
                    <input type="text" name="phone" value="{{ old('phone', $owner->phone) }}" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 @error('phone') border-red-500 @enderror">
                    @error('phone')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                    <input type="text" name="adresse" value="{{ old('adresse', $owner->adresse) }}"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                    <select name="is_active"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                        <option value="1" {{ old('is_active', $owner->is_active) ? 'selected' : '' }}>Actif</option>
                        <option value="0" {{ !old('is_active', $owner->is_active) ? 'selected' : '' }}>Inactif
                        </option>
                    </select>
                </div>
            </div>
        </div>

        {{-- ── Section Véhicules & Contrats (Propriétaires uniquement) ── --}}
        <div class="bg-white rounded-lg shadow-md">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-base font-bold text-gray-800">Véhicules & Contrats</h3>
            </div>
            <div class="px-6 py-5 space-y-5">

                @forelse($owner->vehicles as $vehicle)
                    @php
                        $activeContract = $vehicle->activeVehicleContract;
                        $activeDriverContract = $vehicle->activeDriverContract;
                        $hasDriver = $activeDriverContract !== null;
                        $hasContract = $activeContract !== null;
                    @endphp

                    <div
                        class="border rounded-xl overflow-hidden {{ $hasDriver ? 'border-gray-200' : 'border-[#286b41]/30' }}">

                        {{-- En-tête véhicule --}}
                        <div
                            class="px-4 py-3 flex items-center justify-between {{ $hasDriver ? 'bg-gray-100 border-b border-gray-200' : 'bg-[#286b41]/10 border-b border-[#286b41]/20' }}">
                            <div class="flex items-center gap-2">
                                <i
                                    class="fas fa-truck-pickup {{ $hasDriver ? 'text-gray-500' : 'text-[#286b41]' }} text-sm"></i>
                                <span class="font-semibold text-sm text-gray-800">{{ $vehicle->vehicle_number }}</span>
                                <span
                                    class="text-xs text-gray-500">({{ ucfirst($vehicle->vehicle_type) }}{{ $vehicle->color ? ' · ' . $vehicle->color : '' }})</span>
                            </div>
                            @if ($hasDriver)
                                <span class="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 font-semibold">
                                    <i class="fas fa-lock mr-1"></i>Agent assigné — non modifiable
                                </span>
                            @else
                                <span
                                    class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 font-semibold">
                                    <i class="fas fa-unlock mr-1"></i>Modifiable
                                </span>
                            @endif
                        </div>

                        <div class="px-4 py-4">

                            {{-- ── CAS 1 : Véhicule avec agent actif → lecture seule ── --}}
                            @if ($hasDriver)
                                <p
                                    class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Ce véhicule est assigné à
                                    <strong>{{ $activeDriverContract->driver->user->name ?? 'un agent' }}</strong>.
                                    Les informations du contrat ne peuvent pas être modifiées.
                                </p>
                                @if ($hasContract)
                                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
                                        <div>
                                            <p class="text-xs text-gray-500">Montant total</p>
                                            <p class="font-semibold">
                                                {{ number_format($activeContract->total_amount, 0, ',', ' ') }} FCFA
                                            </p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500">Total payé</p>
                                            <p class="font-semibold text-emerald-700">
                                                {{ number_format($activeContract->total_paid, 0, ',', ' ') }} FCFA</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500">Durée</p>
                                            <p class="font-semibold">{{ $activeContract->contract_months }} mois</p>
                                        </div>
                                    </div>
                                @endif

                                {{-- ── CAS 2 : Véhicule sans agent → tout modifiable ── --}}
                            @else
                                <div class="space-y-4">
                                    {{-- Infos véhicule --}}
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-xs font-medium text-gray-700 mb-1">N°
                                                Immatriculation</label>
                                            <input type="text" name="vehicles[{{ $vehicle->id }}][vehicle_number]"
                                                value="{{ old('vehicles.' . $vehicle->id . '.vehicle_number', $vehicle->vehicle_number) }}"
                                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#286b41] focus:outline-none">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-700 mb-1">Type</label>
                                            <select name="vehicles[{{ $vehicle->id }}][vehicle_type]"
                                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#286b41] focus:outline-none">
                                                <option value="tricycle"
                                                    {{ $vehicle->vehicle_type === 'tricycle' ? 'selected' : '' }}>
                                                    Tricycle</option>
                                                <option value="moto"
                                                    {{ $vehicle->vehicle_type === 'moto' ? 'selected' : '' }}>Moto
                                                </option>
                                                <option value="car"
                                                    {{ $vehicle->vehicle_type === 'car' ? 'selected' : '' }}>
                                                    Voiture</option>
                                            </select>
                                        </div>
                                        <div class="col-span-2">
                                            <label class="block text-xs font-medium text-gray-700 mb-1">Notes</label>
                                            <input type="text" name="vehicles[{{ $vehicle->id }}][vehicle_notes]"
                                                value="{{ old('vehicles.' . $vehicle->id . '.vehicle_notes', $vehicle->notes) }}"
                                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[#286b41]"
                                                placeholder="Informations complémentaires (optionnel)">
                                        </div>
                                    </div>

                                    {{-- Contrat véhicule --}}
                                    @if ($hasContract)
                                        <input type="hidden" name="vehicles[{{ $vehicle->id }}][contract_id]"
                                            value="{{ $activeContract->id }}">
                                        @include('inc.partials.vehicle-contract-fields', [
                                            'prefix' => "vehicles[{$vehicle->id}]",
                                            'context' => "existing_{$vehicle->id}",
                                            'contract' => $activeContract,
                                        ])
                                    @else
                                        {{-- Véhicule sans contrat → proposer d'en créer un --}}
                                        <div class="border border-dashed border-purple-300 rounded-lg p-3">
                                            <p class="text-xs text-purple-600 font-semibold mb-3">
                                                <i class="fas fa-file-contract mr-1"></i>
                                                Aucun contrat actif — créer un contrat pour ce véhicule
                                            </p>
                                            @include('inc.partials.vehicle-contract-fields', [
                                                'prefix' => "vehicles[{$vehicle->id}]",
                                                'context' => "new_contract_{$vehicle->id}",
                                                'contract' => null,
                                            ])
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>

                @empty
                    {{-- Aucun véhicule --}}
                    <div
                        class="bg-gray-50 border border-dashed border-gray-300 rounded-xl px-4 py-6 text-center text-gray-400">
                        <i class="fas fa-truck-pickup text-3xl mb-2"></i>
                        <p class="text-sm">Aucun véhicule associé à ce propriétaire.</p>
                    </div>
                @endforelse

                {{-- ── CAS 3 : Ajouter un véhicule (existant ou nouveau) ── --}}
                <div class="border-t border-dashed border-gray-200 pt-5 space-y-4">
                    <p class="text-xs font-bold text-gray-600 uppercase tracking-wide">
                        <i class="fas fa-plus-circle text-[#286b41] mr-1"></i>
                        Ajouter un véhicule
                    </p>

                    {{-- Cards de choix du mode --}}
                    <div class="grid grid-cols-2 gap-3">
                        <label id="add-mode-existing-label"
                            class="add-mode-card cursor-pointer rounded-xl border-2 border-[#286b41] bg-[#286b41]/10 p-3 flex items-center gap-2 transition">
                            <input type="radio" name="_add_vehicle_mode" value="existing" class="sr-only" checked>
                            <div id="add-icon-existing"
                                class="w-8 h-8 rounded-full bg-[#286b41] flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-search text-white text-xs"></i>
                            </div>
                            <p class="text-sm font-bold text-gray-800">Véhicule existant</p>
                        </label>
                        <label id="add-mode-new-label"
                            class="add-mode-card cursor-pointer rounded-xl border-2 border-gray-200 bg-white p-3 flex items-center gap-2 transition">
                            <input type="radio" name="_add_vehicle_mode" value="new" class="sr-only">
                            <div id="add-icon-new"
                                class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-plus text-gray-400 text-xs"></i>
                            </div>
                            <p class="text-sm font-bold text-gray-800">Nouveau véhicule</p>
                        </label>
                    </div>

                    {{-- Véhicule existant --}}
                    <div id="add-section-existing" class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Véhicule disponible</label>
                            <select name="vehicle_id" id="add-vehicle-select" onchange="onAddVehicleSelected(this.value)"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#286b41] focus:outline-none">
                                <option value="">— Sélectionnez un véhicule —</option>
                                @foreach ($availableVehicles as $v)
                                    <option value="{{ $v->id }}"
                                        {{ old('vehicle_id') == $v->id ? 'selected' : '' }}>
                                        {{ $v->vehicle_number }} ({{ ucfirst($v->vehicle_type) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        {{-- Contrat affiché quand un véhicule est sélectionné --}}
                        <div id="add-existing-contract"
                            class="{{ old('vehicle_id') ? '' : 'hidden' }} border border-dashed border-purple-300 rounded-lg p-3">
                            <p class="text-xs text-purple-600 font-semibold mb-3">
                                <i class="fas fa-file-contract mr-1"></i>
                                Contrat pour ce véhicule <span class="text-gray-400 font-normal">(optionnel)</span>
                            </p>
                            @include('inc.partials.vehicle-contract-fields', [
                                'prefix' => 'existing_vehicle',
                                'context' => 'add-existing',
                                'contract' => null,
                            ])
                        </div>
                    </div>

                    {{-- Nouveau véhicule --}}
                    <div id="add-section-new" class="hidden space-y-3">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">N° Immatriculation</label>
                                <input type="text" name="new_vehicle_number" value="{{ old('new_vehicle_number') }}"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#286b41] focus:outline-none"
                                    placeholder="BJ-0000-AB">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Type</label>
                                <select name="new_vehicle_type"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#286b41] focus:outline-none">
                                    <option value="tricycle">Tricycle</option>
                                    <option value="moto">Moto</option>
                                    <option value="car">Voiture</option>
                                </select>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Notes</label>
                                <input type="text" name="new_vehicle_notes"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[#286b41]"
                                    placeholder="Informations complémentaires (optionnel)">
                            </div>
                        </div>
                        {{-- Contrat affiché directement pour nouveau véhicule --}}
                        <div class="border border-dashed border-purple-300 rounded-lg p-3">
                            <p class="text-xs text-purple-600 font-semibold mb-3">
                                <i class="fas fa-file-contract mr-1"></i>
                                Contrat pour ce véhicule <span class="text-gray-400 font-normal">(optionnel)</span>
                            </p>
                            @include('inc.partials.vehicle-contract-fields', [
                                'prefix' => 'new',
                                'context' => 'add-new',
                                'contract' => null,
                            ])
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- Boutons --}}
        <div class="bg-white rounded-lg shadow-md px-6 py-4 flex justify-end gap-3">
            <a href="{{ route('admin.owners.index') }}"
                class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-semibold">
                Annuler
            </a>
            <button type="submit"
                class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition font-semibold">
                <i class="fas fa-save mr-1"></i> Enregistrer
            </button>
        </div>
    </form>

    @push('scripts')
        <script>
            const CONTRACT_AMOUNTS = @json(\App\Consts\VehicleContractConsts::TOTAL_AMOUNTS);

            // ── Cards de choix du mode ajout ────────────────────────
            document.querySelectorAll('input[name="_add_vehicle_mode"]').forEach(r => {
                r.addEventListener('change', () => switchAddMode(r.value));
            });
            document.querySelectorAll('.add-mode-card').forEach(card => {
                card.addEventListener('click', () => {
                    const r = card.querySelector('input');
                    r.checked = true;
                    switchAddMode(r.value);
                });
            });

            function switchAddMode(mode) {
                const isExisting = mode === 'existing';

                document.getElementById('add-mode-existing-label').className =
                    `add-mode-card cursor-pointer rounded-xl border-2 p-3 flex items-center gap-2 transition
            ${isExisting ? 'border-[#286b41] bg-[#286b41]/10' : 'border-gray-200 bg-white'}`;
                document.getElementById('add-icon-existing').className =
                    `w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0
            ${isExisting ? 'bg-[#286b41]' : 'bg-gray-100'}`;
                document.querySelector('#add-icon-existing i').className =
                    `fas fa-search text-xs ${isExisting ? 'text-white' : 'text-gray-400'}`;

                document.getElementById('add-mode-new-label').className =
                    `add-mode-card cursor-pointer rounded-xl border-2 p-3 flex items-center gap-2 transition
            ${!isExisting ? 'border-purple-600 bg-purple-50' : 'border-gray-200 bg-white'}`;
                document.getElementById('add-icon-new').className =
                    `w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0
            ${!isExisting ? 'bg-purple-600' : 'bg-gray-100'}`;
                document.querySelector('#add-icon-new i').className =
                    `fas fa-plus text-xs ${!isExisting ? 'text-white' : 'text-gray-400'}`;

                document.getElementById('add-section-existing').classList.toggle('hidden', !isExisting);
                document.getElementById('add-section-new').classList.toggle('hidden', isExisting);
            }

            // ── Afficher le contrat quand on sélectionne un véhicule ─
            function onAddVehicleSelected(value) {
                document.getElementById('add-existing-contract').classList.toggle('hidden', !value);
            }

            // ── Pré-remplissage du montant/mensualité selon la durée ─
            function onContractMonthsChange(context) {
                const select = document.getElementById(`contract_months_${context}`);
                const months = select?.value;
                const other = document.getElementById(`other_months_wrap_${context}`);
                const total = document.getElementById(`contract_total_${context}`);
                const monthly = document.getElementById(`contract_monthly_${context}`);

                other?.classList.toggle('hidden', months !== 'other');

                if (months && months !== 'other' && CONTRACT_AMOUNTS[months]) {
                    if (total) total.value = CONTRACT_AMOUNTS[months];
                } else if (months === 'other' || !months) {
                    if (total) total.value = '';
                }
            }
        </script>
    @endpush
@endsection
