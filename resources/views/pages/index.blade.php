@extends('layouts.main')

@php
    $hideGlobalAlerts = true;
@endphp

@section('content')
    <!-- Hero Section avec Formulaire de Réservation -->
    <section id="reservation"
        style="background: url('{{ asset('assets/images/png/tricycle_bg.png') }}') no-repeat center center/cover"
        class="h-[750px] relative">
        <div class="w-full h-full absolute inset-0 bg-black/80"></div>
        <div class="w-full md:max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 absolute inset-0 flex items-center">
            <div class="w-full">
                <div class="block md:grid grid-cols-1 md:grid-cols-2 gap-12 items-center w-full">
                    <!-- Texte Hero -->
                    <div class="text-white hidden md:block">
                        <h2 class="text-5xl font-bold mb-6">Voyagez avec Style et Confort</h2>
                        <p class="text-xl mb-8 text-purple-100">Découvrez une nouvelle façon de vous déplacer avec nos
                            chic tuk tuk. Unique, moderne et confortable.</p>
                        <div class="flex space-x-6 mb-12">
                            <div class="text-center">
                                <div class="text-4xl font-bold">500+</div>
                                <div class="text-purple-200">Courses réalisées</div>
                            </div>
                            <div class="text-center">
                                <div class="text-4xl font-bold">4.9/5</div>
                                <div class="text-purple-200">Note moyenne</div>
                            </div>
                            <div class="text-center">
                                <div class="text-4xl font-bold">50+</div>
                                <div class="text-purple-200">Agents</div>
                            </div>
                        </div>
                        <div class="flex justify-start">
                            <a href="https://wa.me/2290144429017?text=Bonjour%0AJ'aimerais%20avoir%20plus%20d'informations"
                                target="_blank" rel="noopener noreferrer"
                                class="px-6 py-3 rounded-md bg-[#FFB230] font-semibold text-black flex items-center gap-2">
                                <i class="fa-brands fa-whatsapp font-bold text-lg block"></i> Contactez-nous
                            </a>
                        </div>
                    </div>

                    <!-- Formulaire de Réservation Multi-étapes -->
                    <div class="bg-white rounded-2xl shadow-2xl p-8 max-h-[600px] overflow-y-auto custom-scrollbar">
                        <h3 class="text-2xl font-bold text-gray-800 mb-6">Réservez votre course</h3>

                        @if (session('error'))
                            <div class="bg-red-100 text-red-700 p-3 mb-3 rounded">
                                {{ session('error') }}
                            </div>
                        @endif

                        @if ($errors->any())
                            <div class="bg-red-50 border-l-4 border-red-600 p-4 mb-4">
                                <ul class="text-sm text-red-800">
                                    @foreach ($errors->all() as $error)
                                        <li>• {{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <!-- Indicateur d'étapes -->
                        <div class="flex justify-between mb-8">
                            <div class="flex flex-col items-center flex-1">
                                <div id="step1-indicator"
                                    class="w-10 h-10 rounded-full step-active flex items-center justify-center font-bold mb-2">
                                    1</div>
                                <span class="text-xs text-gray-600">Trajet</span>
                            </div>
                            <div class="flex-1 flex items-center justify-center" style="margin-bottom: 26px;">
                                <div class="h-1 bg-gray-300 w-full"></div>
                            </div>
                            <div class="flex flex-col items-center flex-1">
                                <div id="step2-indicator"
                                    class="w-10 h-10 rounded-full bg-[#FFE7C1] text-gray-600 flex items-center justify-center font-bold mb-2">
                                    2</div>
                                <span class="text-xs text-gray-600">Date & Heure</span>
                            </div>
                            <div class="flex-1 flex items-center justify-center" style="margin-bottom: 26px;">
                                <div class="h-1 bg-gray-300 w-full"></div>
                            </div>
                            <div class="flex flex-col items-center flex-1">
                                <div id="step3-indicator"
                                    class="w-10 h-10 rounded-full bg-[#FFE7C1] text-gray-600 flex items-center justify-center font-bold mb-2">
                                    3</div>
                                <span class="text-xs text-gray-600">Détails</span>
                            </div>
                        </div>

                        <form id="bookingForm" action="{{ route('bookings.store') }}" method="POST">
                            @csrf

                            <!-- Étape 1: Trajet -->
                            <div id="step1" class="step-content">
                                {{-- SELECT-TRIP-TYPE --}}

                                <div id="normalTrip">

                                    <div class="mb-4">
                                        <label class="block text-gray-700 font-semibold mb-2">Point de départ <span
                                                class="text-red-500">*</span></label>
                                        <div class="relative">
                                            <input type="text" id="from_input" name="from_location"
                                                class="w-full px-4 py-3 border rounded-lg outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent"
                                                placeholder="Entrez votre ville de départ" required>

                                            <!-- Bouton clear -->
                                            <button type="button" id="from_clear"
                                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hidden">
                                                ✕
                                            </button>
                                        </div>
                                        <p class="text-red-500 text-sm mt-1 hidden error-message"></p>
                                        @error('from_location')
                                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                                        @enderror

                                        <div class="relative">
                                            <div id="from_suggestions"
                                                class="bg-white border rounded mt-1 hidden max-h-[200px] w-full overflow-y-scroll absolute top-0 left-0 z-50">
                                            </div>
                                        </div>

                                        <input type="hidden" name="from_lat" id="from_lat">
                                        <input type="hidden" name="from_lng" id="from_lng">
                                    </div>

                                    <div class="mb-4">
                                        <label class="block text-gray-700 font-semibold mb-2">Destination <span
                                                class="text-red-500">*</span></label>
                                        <div class="relative">
                                            <input type="text" id="to_input" name="to_location"
                                                class="w-full px-4 py-3 border rounded-lg outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent"
                                                placeholder="Où souhaitez-vous aller ?" required>

                                            <!-- Bouton clear -->
                                            <button type="button" id="to_clear"
                                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hidden">
                                                ✕
                                            </button>
                                        </div>
                                        <p class="text-red-500 text-sm mt-1 hidden error-message"></p>
                                        @error('to_location')
                                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                                        @enderror

                                        <div class="relative">
                                            <div id="to_suggestions"
                                                class="bg-white border rounded mt-1 hidden max-h-[200px] w-full overflow-y-scroll absolute top-0 left-0 z-50">
                                            </div>
                                        </div>

                                        <input type="hidden" name="to_lat" id="to_lat">
                                        <input type="hidden" name="to_lng" id="to_lng">
                                    </div>

                                    <div class="my-4 text-sm text-gray-700 hidden" id="pricePreview">
                                        {{-- Distance : <span id="distance">-- km</span><br> --}}
                                        Prix estimé : <span id="preview-price">-- FCFA</span>
                                    </div>
                                </div>

                                {{-- CIRCUIT-TRIP --}}

                                <button type="button" onclick="nextStep(2)"
                                    class="w-full py-3 bg-[#286b41] text-white rounded-lg font-semibold hover:opacity-90 transition">
                                    Suivant <i class="fas fa-arrow-right ml-2"></i>
                                </button>
                            </div>

                            <!-- Étape 2: Date & Heure -->
                            <div id="step2" class="step-content hidden">
                                <div class="mb-4">
                                    <!-- Carte -->
                                    <p class="text-xs text-gray-500 mb-1">
                                        ℹ️ Une majoration de 1 000 FCFA s'applique en dehors de la tranche 6h–10h.
                                    </p>

                                    <label class="block text-gray-700 font-semibold mb-2">Date et heure <span
                                            class="text-red-500">*</span></label>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <input type="date" name="pickup_date" value="{{ old('pickup_date') }}"
                                            id="pickup_date"
                                            class="w-full px-4 py-3 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent"
                                            min="{{ \Carbon\Carbon::now()->addDay()->toDateString() }}" required>
                                        <p class="text-red-500 text-sm mt-1 hidden error-message"></p>
                                        @error('pickup_date')
                                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                                        @enderror

                                        <input type="time" name="pickup_time" value="{{ old('pickup_time') }}"
                                            id="pickup_time"
                                            class="w-full px-4 py-3 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent"
                                            required>
                                        <p class="text-red-500 text-sm mt-1 hidden error-message"></p>
                                        @error('pickup_time')
                                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>

                                <div id="simpleRoundTripWrapper" class="my-4">
                                    <div class="flex items-center gap-3">
                                        <input type="checkbox" name="round_trip" id="simple_round_trip" value="1"
                                            class="w-4 h-4 accent-[#286b41] cursor-pointer">
                                        <label for="simple_round_trip" class="text-gray-700 font-semibold cursor-pointer">
                                            Aller-Retour
                                        </label>
                                    </div>

                                    {{-- Heure de retour --}}
                                    <div id="simpleReturnTimeWrapper" class="mt-3 hidden">
                                        <label class="block text-gray-700 font-semibold mb-2">
                                            Heure de retour <span class="text-red-500">*</span>
                                        </label>
                                        <input type="time" id="simple_return_time"
                                            class="w-full px-4 py-3 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent">
                                        <p class="text-red-500 text-sm mt-1 hidden" id="simple_return_time_error"></p>
                                    </div>
                                </div>

                                <!-- Option multi-jours -->
                                <div class="mb-4">
                                    <label class="inline-flex items-center text-gray-700 font-semibold">
                                        <input type="checkbox" id="multi_day"
                                            class="w-4 h-4 accent-[#286b41] cursor-pointer mr-3">
                                        <span>Réservation sur plusieurs jours</span>
                                    </label>

                                    <!-- Hidden input that holds the actual number of days sent to server (default 1) -->
                                    <input type="hidden" name="days" id="days_hidden" value="1">

                                    <!-- Visible input shown only when multi-day checked (no name attribute) -->
                                    <div id="daysWrapper" class="mt-3 hidden">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-2">
                                            {{-- Nombre de jours --}}
                                            <div>
                                                <label class="block text-gray-700 font-semibold mb-2">Nombre de jours <span
                                                        class="text-red-500">*</span></label>
                                                <input type="text" id="days_input" inputmode="numeric" min="2"
                                                    value="2"
                                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent"
                                                    placeholder="Entrez le nombre de jours">
                                            </div>

                                            {{-- Jours de la semaine --}}
                                            <div>
                                                <label class="block text-gray-700 font-semibold mb-2">
                                                    Jours de circulation <span class="text-red-500">*</span>
                                                </label>
                                                <select name="week_days" id="week_days"
                                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent bg-white">
                                                    <option value="lun_ven">Lun → Ven (5j/7)</option>
                                                    <option value="lun_sam">Lun → Sam (6j/7)</option>
                                                    <option value="lun_dim">Lun → Dim (7j/7)</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                            {{-- Aller-Retour --}}
                                            <div class="flex items-center gap-3">
                                                <input type="checkbox" name="round_trip" id="round_trip" value="1"
                                                    class="w-4 h-4 accent-[#286b41] cursor-pointer">
                                                <label for="round_trip"
                                                    class="text-gray-700 font-semibold cursor-pointer">
                                                    Aller-Retour
                                                </label>
                                            </div>

                                            {{-- Heure de retour (visible uniquement si aller-retour coché) --}}
                                            <div id="returnTimeWrapper" class="mt-3 hidden">
                                                <label class="block text-gray-700 font-semibold mb-2">
                                                    Heure de retour <span class="text-red-500">*</span>
                                                </label>
                                                <input type="time" id="return_time"
                                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent">
                                                <p class="text-red-500 text-sm mt-1 hidden" id="return_time_error"></p>
                                            </div>
                                        </div>
                                    </div>
                                    @error('days')
                                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                <input type="hidden" name="return_time" id="unified_return_time">

                                {{-- PASSENGERS --}}

                                <div class="flex space-x-4">
                                    <button type="button" onclick="prevStep(1)"
                                        class="flex-1 py-3 bg-gray-300 text-gray-700 rounded-lg font-semibold hover:bg-gray-400 transition">
                                        <i class="fas fa-arrow-left mr-2"></i> Retour
                                    </button>
                                    <button type="button" onclick="nextStep(3)"
                                        class="flex-1 py-3 bg-[#286b41] text-white rounded-lg font-semibold hover:opacity-90 transition">
                                        Suivant <i class="fas fa-arrow-right ml-2"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Étape 3: Détails et Confirmation -->
                            <div id="step3" class="step-content hidden">
                                <div class="mb-4">
                                    <label class="block text-gray-700 font-semibold mb-2">Numéro de téléphone <span
                                            class="text-red-500">*</span></label>
                                    <input type="tel" name="phone" value="{{ old('phone') }}" id="phone"
                                        placeholder="01 90 12 34 56" pattern="^\d{6,15}$"
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent"
                                        required>
                                </div>
                                <p class="text-red-500 text-sm mt-1 hidden error-message"></p>
                                @error('phone')
                                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                                @enderror

                                <div class="mb-4">
                                    <label class="block text-gray-700 font-semibold mb-2">Demandes spéciales, veuillez
                                        donner la localisation précise de l'adresse de départ et d'arrivée
                                        (optionnel)</label>
                                    <textarea name="special_requests" rows="3"
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent"
                                        placeholder="Bagages volumineux, animaux, etc."></textarea>
                                </div>
                                {{-- PROMO-CODE --}}

                                {{-- RÉCAPITULATIF UNIFIÉ --}}
                                <div id="bookingSummary"
                                    class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 mb-6 space-y-3">

                                    <p class="text-sm font-semibold text-gray-700 flex items-center gap-2">
                                        <i class="fas fa-receipt text-[#286b41]"></i>
                                        Récapitulatif de la réservation
                                    </p>

                                    {{-- Trajet --}}
                                    <div class="flex justify-between text-sm text-gray-600">
                                        <span class="text-gray-500">De</span>
                                        <span id="recap-from"
                                            class="font-medium text-gray-700 text-right max-w-[60%] truncate">--</span>
                                    </div>
                                    <div class="flex justify-between text-sm text-gray-600">
                                        <span class="text-gray-500">Vers</span>
                                        <span id="recap-to"
                                            class="font-medium text-gray-700 text-right max-w-[60%] truncate">--</span>
                                    </div>

                                    {{-- Date & heure --}}
                                    <div class="flex justify-between text-sm text-gray-600">
                                        <span class="text-gray-500">Date de départ</span>
                                        <span id="recap-date" class="font-medium text-gray-700">--</span>
                                    </div>
                                    <div class="flex justify-between text-sm text-gray-600">
                                        <span class="text-gray-500">Heure</span>
                                        <span id="recap-time" class="font-medium text-gray-700">--</span>
                                    </div>

                                    <div class="border-t border-gray-200"></div>

                                    {{-- Bloc course unique --}}
                                    <div id="recap-single">
                                        <div class="flex justify-between text-sm text-gray-600">
                                            <span class="text-gray-500">Type</span>
                                            <span id="recap-single-type" class="font-medium text-gray-700">Trajet
                                                unique</span>
                                        </div>

                                        {{-- Heure de retour (visible si aller-retour) --}}
                                        <div id="recap-single-return-row"
                                            class="flex justify-between text-sm text-gray-600 mt-2 hidden">
                                            <span class="text-gray-500">Heure de retour</span>
                                            <span id="recap-single-return-time"
                                                class="font-medium text-gray-700">--</span>
                                        </div>

                                        <div class="flex justify-between text-sm text-gray-600 mt-2">
                                            <span class="text-gray-500">Prix du trajet</span>
                                            <span id="recap-single-base-price" class="font-medium text-gray-700">--
                                                FCFA</span>
                                        </div>

                                        {{-- Ligne ×2 visible si aller-retour --}}
                                        <div id="recap-single-round-row"
                                            class="flex justify-between text-sm text-gray-600 mt-2 hidden">
                                            <span class="text-gray-500">Aller-Retour</span>
                                            <span class="font-medium text-gray-700">× 2</span>
                                        </div>

                                    </div>

                                    {{-- Bloc abonnement (caché par défaut) --}}
                                    <div id="recap-subscription" class="hidden space-y-2">
                                        <div class="flex justify-between text-sm text-gray-600">
                                            <span class="text-gray-500">Type</span>
                                            <span
                                                class="inline-flex items-center gap-1 font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full text-xs">
                                                <i class="fas fa-rotate"></i> Abonnement
                                            </span>
                                        </div>
                                        <div class="flex justify-between text-sm text-gray-600">
                                            <span class="text-gray-500">Prix par trajet (Aller)</span>
                                            <span id="recap-sub-unit" class="font-medium text-gray-700">-- FCFA</span>
                                        </div>
                                        <div class="flex justify-between text-sm text-gray-600" id="recap-sub-round-row">
                                            <span class="text-gray-500">Retour</span>
                                            <span class="font-medium text-gray-700">× 2</span>
                                        </div>
                                        <div class="flex justify-between text-sm text-gray-600">
                                            <span class="text-gray-500">Nombre de jrs</span>
                                            <span id="recap-sub-days" class="font-medium text-gray-700">--</span>
                                        </div>
                                        <div class="flex justify-between text-sm text-gray-600">
                                            <span class="text-gray-500 text-nowrap">Jrs de circulat*</span>
                                            <span id="recap-sub-weekdays"
                                                class="font-medium text-gray-700 text-nowrap">--</span>
                                        </div>
                                    </div>

                                    {{-- Total --}}
                                    <div
                                        class="border-t border-gray-200 pt-3 flex justify-between font-bold text-base text-[#286b41]">
                                        <span>Total</span>
                                        <span id="total-price">-- FCFA</span>
                                    </div>

                                </div>

                                <div class="flex space-x-4">
                                    <button type="button" onclick="prevStep(2)"
                                        class="flex-1 py-3 bg-gray-300 text-gray-700 rounded-lg font-semibold hover:bg-gray-400 transition">
                                        <i class="fas fa-arrow-left mr-2"></i> Retour
                                    </button>
                                    <button type="submit"
                                        class="flex-1 py-3 gradient-bg text-white rounded-lg font-semibold hover:opacity-90 transition">
                                        Confirmer <i class="fas fa-check ml-2"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Étape 4: Succès (invisible dans l’indicateur) -->
                            <div id="step4" class="step-content hidden">
                                <div
                                    class="relative overflow-hidden rounded-2xl border border-emerald-200 bg-gradient-to-br from-emerald-50 via-white to-emerald-50 p-8">
                                    <!-- Confettis CSS -->
                                    <div class="confetti">
                                        @for ($i = 1; $i <= 18; $i++)
                                            <span class="confetti-piece"></span>
                                        @endfor
                                    </div>

                                    <div class="text-center">
                                        <div
                                            class="mx-auto w-20 h-20 rounded-full bg-emerald-100 flex items-center justify-center shadow-lg success-pop">
                                            <i class="fas fa-check text-4xl text-emerald-600"></i>
                                        </div>

                                        <h4 class="mt-6 text-3xl font-extrabold text-gray-900 success-pop"
                                            style="animation-delay:.05s">
                                            Réservation envoyée !
                                        </h4>

                                        <p class="mt-2 text-gray-600 text-base success-pop" style="animation-delay:.1s">
                                            Merci 🙌 Votre demande a bien été enregistrée. Un chauffeur vous contactera très
                                            bientôt.
                                        </p>

                                        <div class="mt-8">
                                            <button type="button" onclick="newBooking()"
                                                class="inline-flex items-center justify-center px-7 py-3 rounded-xl bg-[#286b41] text-white font-semibold shadow-lg hover:opacity-95 active:scale-[.99] transition success-pop"
                                                style="animation-delay:.2s">
                                                Nouvelle réservation <i class="fas fa-plus ml-2"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Comment ça marche -->
    @include('pages.components.how_it_work')

    <!-- Ce que nous offrons -->
    @include('pages.components.advantages')

    <!-- Ils nous soutiennent -->
    @include('pages.components.partners')

    <!-- Témoignages -->
    @include('pages.components.testimonials')

    @push('scripts')
        <script>
            $(function() {
                var currentStep = 1;

                /* MAP & ICONS */

                // ==========================
                // 🔎 AUTOCOMPLETE (Nominatim)
                // ==========================
                // debounce pour éviter trop d'appels API
                function debounce(fn, delay = 400) {
                    let timeout;
                    return (...args) => {
                        clearTimeout(timeout);
                        timeout = setTimeout(() => fn(...args), delay);
                    };
                }

                // skeleton loader
                function skeletonHTML() {
                    return `
                            <div class="p-3 space-y-2">
                                <div class="h-4 bg-gray-200 animate-pulse rounded"></div>
                                <div class="h-4 bg-gray-200 animate-pulse rounded"></div>
                                <div class="h-4 bg-gray-200 animate-pulse rounded"></div>
                            </div>
                            `;
                }

                // message UI
                function messageHTML(text) {
                    return `<div class="p-3 text-sm text-gray-500">${text}</div>`;
                }

                //search city
                async function searchCity(query) {
                    if (query.length < 3) return [];

                    const res = await fetch(
                        `https://nominatim.openstreetmap.org/search?format=json&countrycodes=bj&q=${query}`);
                    return await res.json();
                }

                function setupAutocomplete(inputId, suggestionsId, latId, lngId, clearId, isFrom) {
                    const input = $('#' + inputId);
                    const box = $('#' + suggestionsId);
                    const clearBtn = $('#' + clearId);

                    // clear button
                    clearBtn.on("click", () => {
                        input.val("");
                        $('#' + latId).val("");
                        $('#' + lngId).val("");
                        box.addClass("hidden");
                        clearBtn.addClass("hidden");
                    });

                    const handleSearch = debounce(async () => {
                        const query = input.val().trim();

                        // ❌ moins de 3 caractères
                        if (query.length < 3) {
                            box.addClass("hidden");
                            clearBtn.toggleClass("hidden", !query);
                            return;
                        }

                        clearBtn.removeClass("hidden");

                        // skeleton
                        box.html(skeletonHTML());
                        box.removeClass("hidden");

                        try {
                            const results = await searchCity(query);

                            box.html("");

                            // ❌ aucun résultat
                            if (!results.length) {
                                box.html(messageHTML(
                                    "Aucune ville ne correspond à votre recherche. Soyez plus précis (ex: Cotonou, Abomey-Calavi...)."
                                ));
                                return;
                            }

                            results.forEach(place => {
                                const div = $('<div></div>');
                                div.addClass("p-2 hover:bg-gray-100 cursor-pointer text-sm");
                                div.text(place.display_name);

                                div.on('click', () => {
                                    input.val(place.display_name);
                                    $('#' + latId).val(place.lat);
                                    $('#' + lngId).val(place.lon);
                                    box.addClass("hidden");

                                    /* MAP VIEW */

                                    calculateRoute();
                                });

                                box.append(div);
                            });

                        } catch (error) {
                            console.error(error);
                            box.html(messageHTML(
                                "Erreur lors de la recherche. Vérifiez votre connexion et réessayez."
                            ));
                        }
                    });

                    input.on("input", handleSearch);

                    // fermer suggestions si clic ailleurs
                    $(document).on("click", (e) => {
                        if (!input.is(e.target) && !box.is(e.target) && box.has(e.target).length === 0) {
                            box.addClass("hidden");
                        }
                    });
                }

                // Init autocomplete
                setupAutocomplete("from_input", "from_suggestions", "from_lat", "from_lng", "from_clear", true);
                setupAutocomplete("to_input", "to_suggestions", "to_lat", "to_lng", "to_clear", false);

                // Fonction pour essayer de calculer le prix au chargement si les inputs ont des valeurs
                function tryCalculateOnLoad() {
                    const fromVal = $('#from_input').val().trim();
                    const toVal = $('#to_input').val().trim();
                    if (fromVal && toVal) {
                        calculateRoute();
                    }
                }

                // Appeler après un court délai pour s'assurer que tout est chargé
                setTimeout(tryCalculateOnLoad, 500);

                // ==========================
                // 📏 CALCUL DISTANCE
                // ==========================
                async function calculateRoute() {
                    const fromLat = $("#from_lat").val();
                    const fromLng = $("#from_lng").val();
                    const toLat = $("#to_lat").val();
                    const toLng = $("#to_lng").val();

                    if (!fromLat || !toLat) return;

                    $("#preview-price").html(skeletonHTML());

                    $("#pricePreview").removeClass("hidden");

                    try {
                        const res = await fetch("/pricing/price", {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/json",
                                "X-CSRF-TOKEN": "{{ csrf_token() }}"
                            },
                            body: JSON.stringify({
                                fromLng,
                                fromLat,
                                toLng,
                                toLat
                            })
                        });

                        const data = await res.json();

                        unitPrice = parseFloat(data.price) || 0;

                        $("#preview-price").text(data.price + " FCFA");

                        // Si abonnement actif, recalculer le total
                        if ($('#multi_day').is(':checked')) {
                            updateSubscriptionSummary();
                        } else {
                            $('#total-price').text(data.price.toLocaleString() + ' FCFA');
                        }

                        $("#pricePreview").removeClass("hidden");

                        // ==========================
                        // 🗺️ TRACE DE LA ROUTE
                        // ==========================

                    } catch (error) {
                        console.error("Erreur lors du calcul de la route:", error);
                        $("#preview-price").text("Erreur de calcul");
                        $("#total-price").text("Erreur de calcul");
                        $("#pricePreview").removeClass("hidden");
                    }
                }

                function showError(input, message) {
                    const container = $(input).closest('.mb-4');
                    const error = container.find('.error-message');

                    if (error.length) {
                        error.text(message);
                        error.removeClass('hidden');
                    }

                    $(input).addClass('border-red-500');
                }

                function clearError(input) {
                    const container = $(input).closest('.mb-4');
                    const error = container.find('.error-message');

                    if (error.length) {
                        error.text("");
                        error.addClass('hidden');
                    }

                    $(input).removeClass('border-red-500');
                }

                function validateStep(stepNumber) {
                    const step = $('#step' + stepNumber);
                    const inputs = step.find('input, textarea, select');

                    let isValid = true;

                    inputs.each(function() {
                        const input = $(this);
                        if (!input.attr('required')) return;

                        clearError(input[0]);

                        // champ vide
                        if (!input.val().trim()) {
                            showError(input[0], "Ce champ est obligatoire");
                            isValid = false;
                            return;
                        }

                        // validation téléphone
                        if (input.attr('type') === "tel") {
                            const regex = /^\d{6,15}$/;
                            if (!regex.test(input.val())) {
                                showError(input[0], "Numéro invalide");
                                isValid = false;
                                return;
                            }
                        }

                        // validation date
                        if (input.attr('type') === "date") {
                            const tomorrow = new Date();
                            tomorrow.setDate(tomorrow.getDate() + 1);
                            tomorrow.setHours(0, 0, 0, 0);
                            const selected = new Date(input.val());

                            if (selected < tomorrow) {
                                showError(input[0], "Choisissez une date à partir d'aujourd'hui");
                                isValid = false;
                                return;
                            }
                        }

                        // validation heure
                        if (input.attr('type') === "time") {
                            const dateInput = step.find('input[type="date"]');
                            if (dateInput.length) {
                                const selectedDate = new Date(dateInput.val());
                                const today = new Date();
                                today.setHours(0, 0, 0, 0);
                                selectedDate.setHours(0, 0, 0, 0);

                                if (selectedDate.getTime() === today.getTime()) {
                                    const now = new Date();
                                    const selectedTime = new Date();
                                    const [hours, minutes] = input.val().split(':');
                                    selectedTime.setHours(parseInt(hours), parseInt(minutes), 0, 0);

                                    if (selectedTime <= now) {
                                        showError(input[0], "Choisissez une heure dans le futur");
                                        isValid = false;
                                        return;
                                    }
                                }
                            }
                        }

                        // Validation heure de retour (si aller-retour coché)
                        if (input.attr('id') === 'return_time' && $('#round_trip').is(':checked')) {
                            const departureTime = $('#pickup_time').val();
                            if (!input.val()) {
                                showError(input[0], "L'heure de retour est obligatoire");
                                isValid = false;
                                return;
                            }
                            if (departureTime && input.val() <= departureTime) {
                                showError(input[0], "L'heure de retour doit être après l'heure de départ");
                                isValid = false;
                                return;
                            }
                        }
                    });

                    return isValid;
                }

                function setIndicator(activeStep) {
                    // Indicateurs seulement 1..3 (step4 est invisible)
                    for (let i = 1; i <= 3; i++) {
                        const $ind = $('#step' + i + '-indicator');
                        if (!$ind.length) continue;

                        if (i === activeStep) {
                            $ind.addClass('step-active')
                                .removeClass('bg-[#FFE7C1] text-gray-600 bg-gray-300');
                        } else {
                            $ind.removeClass('step-active')
                                .addClass('bg-[#FFE7C1] text-gray-600');
                        }
                    }
                }

                function showStep(step) {
                    // cacher 1..4
                    for (let i = 1; i <= 4; i++) $('#step' + i).addClass('hidden');

                    // afficher step demandé
                    $('#step' + step).removeClass('hidden');

                    currentStep = step;

                    // Indicateur : si step4 => on garde step3 actif (ou step1 si tu préfères)
                    if (step === 4) {
                        setIndicator(3);
                    } else {
                        setIndicator(step);
                    }
                }

                window.nextStep = function(step) {
                    if (!validateStep(currentStep)) return;
                    showStep(step);
                    if (step === 3) updateRecap();
                };

                window.prevStep = function(step) {
                    showStep(step);
                };

                $('#bookingForm').on('submit', function() {
                    var isSubscription = $('#multi_day').is(':checked');

                    if (isSubscription) {
                        $('#unified_return_time').val($('#return_time').val());
                    } else {
                        $('#unified_return_time').val($('#simple_return_time').val());
                    }
                });

                window.newBooking = function() {
                    const form = $('#bookingForm')[0];
                    form.reset();
                    unitPrice = 0;
                    // reset multi-day
                    $('#days_hidden').val(1);
                    $('#daysWrapper').addClass('hidden');
                    $('#multi_day').prop('checked', false);

                    // reset prix UI
                    $('#pricePreview').addClass('hidden');
                    $('#preview-price').text('-- FCFA');
                    $('#total-price').text('-- FCFA');

                    // reset message promo si tu l’utilises
                    $('#promo-message').html('');

                    $('#recap-from, #recap-to, #recap-date, #recap-time').text('--');
                    $('#recap-single-price').text('-- FCFA');
                    $('#recap-subscription').addClass('hidden');
                    $('#recap-single').removeClass('hidden');
                    $('#returnTimeWrapper').addClass('hidden');
                    $('#return_time').removeAttr('required').val('');

                    showStep(1);
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                };

                window.selectTripType = function(e, type) {
                    $('#trip_type').val(type);

                    $('.trip-type-btn').each(function() {
                        $(this).removeClass('border-emerald-600 text-emerald-600 bg-emerald-50').addClass(
                            'border-gray-300 text-gray-600');
                    });

                    var $btn = $(e.target).closest('.trip-type-btn');
                    $btn.addClass('border-emerald-600 text-emerald-600 bg-emerald-50').removeClass(
                        'border-gray-300 text-gray-600');

                    if (type === 'normal') {
                        $('#normalTrip').removeClass('hidden');
                        $('#circuitTrip').addClass('hidden');
                    } else {
                        $('#normalTrip').addClass('hidden');
                        $('#circuitTrip').removeClass('hidden');
                    }
                };

                // ================================
                // ABONNEMENT MULTI-JOURS
                // ================================

                // Prix unitaire retourné par l'API (mis à jour par calculateRoute)
                var unitPrice = 0;

                // Labels lisibles pour les jours de semaine
                var weekDaysLabels = {
                    'lun_ven': 'Lun → Ven (5j/7)',
                    'lun_sam': 'Lun → Sam (6j/7)',
                    'lun_dim': 'Lun → Dim (7j/7)',
                };

                function timeSurcharge(price, time) {
                    if (!time) return price;
                    const [h, m] = time.split(':').map(Number);
                    const minutes = h * 60 + m;
                    const start = 6 * 60;
                    const end = 10 * 60;
                    const inNormalWindow = minutes >= start && minutes <= end;
                    return inNormalWindow ? price : price + 1000;
                }

                function updateRecap() {
                    var isSubscription = $('#multi_day').is(':checked');
                    var days = parseInt($('#days_hidden').val()) || 1;
                    var isRound = isSubscription ?
                        $('#round_trip').is(':checked') :
                        $('#simple_round_trip').is(':checked');
                    var pickupTime = $('#pickup_time').val();
                    var returnTime = isSubscription ? $('#return_time').val() : $('#simple_return_time').val();
                    var goPrice = timeSurcharge(unitPrice, pickupTime);
                    var returnPrice = isRound ? timeSurcharge(unitPrice, returnTime) : goPrice;
                    var tripPrice = isRound ? (goPrice + returnPrice) : goPrice;
                    var total = isSubscription ? tripPrice * days : tripPrice;

                    // Trajet & horaire
                    $('#recap-from').text($('#from_input').val() || '--');
                    $('#recap-to').text($('#to_input').val() || '--');
                    $('#recap-date').text($('#pickup_date').val() || '--');
                    $('#recap-time').text($('#pickup_time').val() || '--');

                    if (isSubscription) {
                        // Afficher bloc abonnement, masquer bloc unique
                        $('#recap-single').addClass('hidden');
                        $('#recap-subscription').removeClass('hidden');

                        $('#recap-sub-unit').text(goPrice.toLocaleString() + ' FCFA');
                        $('#recap-sub-days').text(days + ' jour(s)');
                        $('#recap-sub-weekdays').text(weekDaysLabels[$('#week_days').val()] || '--');

                        if (isRound) {
                            $('#recap-sub-round-row').removeClass('hidden');
                            // Ajouter l'heure de retour dans le récap
                            var returnTime = $('#return_time').val();
                            $('#recap-sub-round-row').html(`
                                <span class="text-gray-500">Retour</span>
                                <span class="font-medium text-gray-700">
                                    ${returnPrice.toLocaleString()} FCFA ${returnTime ? '— retour à ' + returnTime : ''}
                                </span>
                            `);
                        } else {
                            $('#recap-sub-round-row').addClass('hidden');
                        }
                    } else {
                        // Afficher bloc unique, masquer bloc abonnement
                        $('#recap-single').removeClass('hidden');
                        $('#recap-subscription').addClass('hidden');

                        // Afficher aller-retour dans le récap course simple
                        if (isRound) {
                            $('#recap-single-type').text('Trajet unique — Aller-Retour');
                            $('#recap-single-base-price').text(goPrice ? goPrice.toLocaleString() + ' FCFA' :
                                '-- FCFA');
                            $('#recap-single-round-row').removeClass('hidden');
                            $('#recap-single-round-row').html(`
                                    <span class="text-gray-500">Retour</span>
                                    <span class="font-medium text-gray-700">${returnPrice.toLocaleString()} FCFA</span>
                                `);
                            $('#recap-single-return-row').removeClass('hidden');
                            $('#recap-single-return-time').text(returnTime || '--');
                        } else {
                            $('#recap-single-type').text('Trajet unique');
                            $('#recap-single-base-price').text(goPrice ? goPrice.toLocaleString() + ' FCFA' :
                                '-- FCFA');
                            $('#recap-single-round-row').addClass('hidden');
                            $('#recap-single-return-row').addClass('hidden');
                        }
                    }

                    // Total
                    if (unitPrice) {
                        $('#total-price').text(total.toLocaleString() + ' FCFA');
                    }
                }

                // Mettre à jour le récap abonnement
                function updateSubscriptionSummary() {
                    updateRecap();
                }

                // Checkbox aller-retour (course simple)
                $('#simple_round_trip').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('#simpleReturnTimeWrapper').removeClass('hidden');
                        $('#simple_return_time').attr('required', true);
                    } else {
                        $('#simpleReturnTimeWrapper').addClass('hidden');
                        $('#simple_return_time').removeAttr('required').val('');
                        $('#simple_return_time_error').addClass('hidden');
                    }

                    // Recalculer le prix si déjà calculé
                    if (unitPrice) updateRecap();
                });

                $('#simple_return_time').on('change', function() {
                    const depTime = $('#pickup_time').val();
                    if (depTime && this.value <= depTime) {
                        $('#simple_return_time_error')
                            .text("L'heure de retour doit être après l'heure de départ.")
                            .removeClass('hidden');
                    } else {
                        $('#simple_return_time_error').addClass('hidden');
                    }
                    updateRecap();
                });

                // Gestion réservation multi-jours
                $('#multi_day').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('#simpleRoundTripWrapper').addClass('hidden');
                        $('#simple_round_trip').prop('checked', false);
                        $('#simpleReturnTimeWrapper').addClass('hidden');
                        $('#simple_return_time').removeAttr('required').val('');
                        $('#simple_return_time_error').addClass('hidden');

                        $('#daysWrapper').removeClass('hidden');
                        $('#days_hidden').val($('#days_input').val());
                    } else {
                        $('#simpleRoundTripWrapper').removeClass('hidden');

                        $('#daysWrapper').addClass('hidden');
                        $('#days_hidden').val(1);
                    }
                    updateSubscriptionSummary()
                });

                $('#days_input').on('input', function() {
                    // Remove non-numeric characters in real-time
                    var v = $(this).val().replace(/[^0-9]/g, '');
                    $(this).val(v);

                    // Update hidden input while typing
                    if (v) {
                        $('#days_hidden').val(v);
                        updateRecap();
                    }
                });

                // Validate on blur (when user leaves the field)
                $('#days_input').on('blur', function() {
                    var v = $(this).val().replace(/[^0-9]/g, '');
                    var num = parseInt(v, 10);

                    // If empty or less than 2, set to 2
                    if (isNaN(num) || num < 2) {
                        num = 2;
                    }

                    $(this).val(num);
                    $('#days_hidden').val(num);
                    updateRecap();
                });

                // Changement jours de semaine
                $('#week_days').on('change', function() {
                    updateRecap();
                });

                // Checkbox aller-retour
                $('#round_trip').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('#returnTimeWrapper').removeClass('hidden');
                        $('#return_time').attr('required', true);
                    } else {
                        $('#returnTimeWrapper').addClass('hidden');
                        $('#return_time').removeAttr('required').val('');
                        $('#return_time_error').addClass('hidden');
                    }
                    updateRecap();
                });

                $('a[href^="#"]').on('click', function(e) {
                    e.preventDefault();
                    var target = $($(this).attr('href'));
                    if (target.length) {
                        $('html, body').animate({
                            scrollTop: target.offset().top
                        }, 600);
                    }
                });

                // ✅ Afficher step4 automatiquement si succès (flash session)
                const hasSuccess = @json((bool) session('success'));
                if (hasSuccess) {
                    showStep(4);
                } else {
                    showStep(1);
                }
            });
        </script>
    @endpush
@endsection
