@extends('layouts.app')

@section('content')
    <!-- Header -->
    <div class="bg-white rounded-lg shadow-md mb-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <div>
                <h1 class="text-lg md:text-2xl font-bold text-gray-800">Modifier la Réservation</h1>
                <p class="text-sm md:text-base text-gray-600">N° {{ $booking->booking_number }}</p>
            </div>
            <div class="flex space-x-3">
                <a href="{{ route('admin.bookings.show', $booking) }}"
                    class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition">
                    <i class="fas fa-arrow-left mr-2"></i> Retour
                </a>
            </div>
        </div>
    </div>

    <!-- Formulaire de modification -->
    <form action="{{ route('admin.bookings.update', $booking) }}" method="POST" class="bg-white rounded-lg shadow-md">
        @csrf
        @method('PUT')

        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-800">Informations de la Réservation</h3>
        </div>

        <div class="px-6 py-6 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Nom du client -->
                <div>
                    <label for="client_name" class="block text-sm font-medium text-gray-700">Nom du client</label>
                    <input type="text" name="client_name" id="client_name"
                        value="{{ old('client_name', $booking->client_name ?? 'Client') }}"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#286b41] focus:border-[#286b41]">
                </div>

                <!-- Client -->
                <div>
                    <label for="user_id" class="block text-sm font-medium text-gray-700">Client</label>
                    <select name="user_id" id="user_id"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#286b41] focus:border-[#286b41]">
                        <option value="{{ $booking->user_id }}" selected>{{ $booking->user->name ?? 'Client' }}</option>
                        <!-- Ici, vous pouvez ajouter d'autres options si nécessaire -->
                    </select>
                </div>

                <!-- Téléphone -->
                <div>
                    <label for="phone" class="block text-sm font-medium text-gray-700">Téléphone</label>
                    <input type="text" name="phone" id="phone" value="{{ $booking->phone }}"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#286b41] focus:border-[#286b41]">
                </div>

                <!-- Zone de départ -->
                <div>
                    <label for="from_zone_id" class="block text-sm font-medium text-gray-700">Zone de départ</label>
                    <div class="relative">
                        <input type="text" id="from_input" name="from_location" value="{{ $booking->from_location }}"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#286b41] focus:border-[#286b41]"
                            placeholder="Entrez votre ville de départ" required>

                        <!-- Bouton clear -->
                        <button type="button" id="from_clear"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hidden">
                            ✕
                        </button>
                    </div>
                    <div class="relative">
                        <div id="from_suggestions"
                            class="bg-white border rounded mt-1 hidden max-h-[200px] w-full overflow-y-scroll absolute top-0 left-0 z-50">
                        </div>
                    </div>

                    <input type="hidden" name="from_lat" value="{{ $booking->from_lat }}" id="from_lat">
                    <input type="hidden" name="from_lng" value="{{ $booking->from_lng }}" id="from_lng">
                </div>

                <!-- Zone d'arrivée -->
                <div>
                    <label for="to_zone_id" class="block text-sm font-medium text-gray-700">Zone d'arrivée</label>
                    <div class="relative">
                        <input type="text" id="to_input" name="to_location" value="{{ $booking->to_location }}"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#286b41] focus:border-[#286b41]"
                            placeholder="Où souhaitez-vous aller ?" required>

                        <!-- Bouton clear -->
                        <button type="button" id="to_clear"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hidden">
                            ✕
                        </button>
                    </div>
                    <div class="relative">
                        <div id="to_suggestions"
                            class="bg-white border rounded mt-1 hidden max-h-[200px] w-full overflow-y-scroll absolute top-0 left-0 z-50">
                        </div>
                    </div>

                    <input type="hidden" name="to_lat" value="{{ $booking->to_lat }}" id="to_lat">
                    <input type="hidden" name="to_lng" value="{{ $booking->to_lng }}" id="to_lng">
                </div>

                <!-- Date et heure de départ -->
                <div>
                    <label for="pickup_date" class="block text-sm font-medium text-gray-700">Date de départ</label>
                    <input type="date" name="pickup_date" id="pickup_date"
                        value="{{ $booking->pickup_date?->format('Y-m-d') }}"
                        min="{{ \Carbon\Carbon::now()->toDateString() }}"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#286b41] focus:border-[#286b41]">
                </div>

                <div>
                    <label for="pickup_time" class="block text-sm font-medium text-gray-700">Heure de départ</label>
                    <input type="time" name="pickup_time" id="pickup_time" value="{{ $booking->pickup_time_formatted }}"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#286b41] focus:border-[#286b41]">
                </div>

                <!-- Nombre de passagers -->
                <div>
                    <label for="days" class="block text-sm font-medium text-gray-700">Nombre de jours</label>
                    <input type="number" name="days" id="days" value="{{ old('days', $booking->days) }}"
                        min="1"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#286b41] focus:border-[#286b41]">
                </div>

                <!-- Aller-Retour -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Trajet</label>
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="round_trip" id="round_trip" value="1"
                            {{ old('round_trip', $booking->round_trip) ? 'checked' : '' }}
                            class="w-4 h-4 text-purple-600 rounded">
                        <span class="ml-2 text-sm text-gray-700">Aller-Retour</span>
                    </label>
                </div>

                <!-- Heure de retour (conditionnel) -->
                <div id="returnTimeWrapper" class="hidden">
                    <label for="return_time" class="block text-sm font-medium text-gray-700">Heure de retour</label>
                    <input type="time" name="return_time" id="return_time"
                        value="{{ old('return_time', $booking->return_time) }}"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#286b41] focus:border-[#286b41]">
                </div>

                <!-- Jours de la semaine (si multi-jour) -->
                <div id="weekDaysWrapper" class="hidden">
                    <label for="week_days" class="block text-sm font-medium text-gray-700">Jours de la semaine</label>
                    <select name="week_days" id="week_days"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#286b41] focus:border-[#286b41]">
                        <option value="">Sélectionner les jours</option>
                        <option value="lun_ven"
                            {{ old('week_days', $booking->week_days) == 'lun_ven' ? 'selected' : '' }}>Lun → Ven (5j/sem)
                        </option>
                        <option value="lun_sam"
                            {{ old('week_days', $booking->week_days) == 'lun_sam' ? 'selected' : '' }}>Lun → Sam (6j/sem)
                        </option>
                        <option value="lun_dim"
                            {{ old('week_days', $booking->week_days) == 'lun_dim' ? 'selected' : '' }}>Lun → Dim (7j/sem)
                        </option>
                    </select>
                </div>

                <!-- Prix total -->
                <div>
                    <label for="base_price" class="block text-sm font-medium text-gray-700">Prix du trajet (FCFA)</label>
                    <input type="number" name="base_price" id="base_price" value="{{ $booking->raw_price }}"
                        step="0.01"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#286b41] focus:border-[#286b41]">
                    <span class="mt-1 text-red-500 hidden" id="price-error"></span>
                    <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2 mt-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        Ce montant doit être le prix <strong>brut</strong> (calculé selon la distance uniquement),
                        <strong>sans la majoration horaire</strong> de 1 000 FCFA. La majoration est appliquée
                        automatiquement à l'enregistrement si l'heure de départ (ou de retour) est en dehors de la tranche
                        6h–10h.
                        Ne saisissez pas manuellement un prix qui inclut déjà cette majoration, sinon elle sera comptée deux
                        fois.
                    </p>
                </div>

                <!-- Statut -->
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700">Statut</label>
                    <select name="status" id="status"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#286b41] focus:border-[#286b41]">
                        <option value="">Selectionner le statut</option>
                        <option value="pending" {{ $booking->status == 'pending' ? 'selected' : '' }}>En attente
                        </option>
                        <option value="confirmed" {{ $booking->status == 'confirmed' ? 'selected' : '' }}>Confirmée
                        </option>
                        {{-- <option value="" {{ $booking->status == 'in_progress' ? 'selected' : '' }}>En cours
                    </option>
                    <option value="" {{ $booking->status == 'completed' ? 'selected' : '' }}>Terminé
                    </option> --}}
                        <option value="cancelled" {{ $booking->status == 'cancelled' ? 'selected' : '' }}>Annulée
                        </option>
                    </select>
                </div>

                <!-- Circuit touristique -->
                {{-- <div>
                    <label for="tourist_circuit_id" class="block text-sm font-medium text-gray-700">Circuit
                        touristique</label>
                    <select name="tourist_circuit_id" id="tourist_circuit_id"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#286b41] focus:border-[#286b41]">
                        <option value="">Aucun</option>
                        @foreach ($touristCircuits as $circuit)
                            <option value="{{ $circuit->id }}"
                                {{ $booking->tourist_circuit_id == $circuit->id ? 'selected' : '' }}>
                                {{ $circuit->name }}</option>
                        @endforeach
                    </select>
                </div> --}}
            </div>

            <!-- Instructions spéciales -->
            <div>
                <label for="special_requests" class="block text-sm font-medium text-gray-700">Instructions
                    spéciales</label>
                <textarea name="special_requests" id="special_requests" rows="4"
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#286b41] focus:border-[#286b41]">{{ $booking->special_requests }}</textarea>
            </div>

            <!-- Boutons -->
            <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200">
                <a href="{{ route('admin.bookings.show', $booking) }}"
                    class="bg-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-400 transition">
                    Annuler
                </a>
                <button type="submit"
                    class="bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 transition">
                    <i class="fas fa-save mr-2"></i> Enregistrer les modifications
                </button>
            </div>
        </div>
    </form>

    @push('scripts')
        <script>
            $(function() {
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

                function initClearButton(inputId, clearId) {
                    const input = $('#' + inputId);
                    const clearBtn = $('#' + clearId);

                    if (input.val().trim().length > 0) {
                        clearBtn.removeClass("hidden");
                    }

                    // afficher / cacher dynamiquement aussi
                    input.on("input", () => {
                        clearBtn.toggleClass("hidden", !input.val().trim());
                    });
                }

                // Init pour les deux champs
                initClearButton("from_input", "from_clear");
                initClearButton("to_input", "to_clear");

                // Auto calcul si données déjà présentes
                if ($("#from_lat").val() && $("#to_lat").val()) {
                    calculateRoute();
                }

                // ==========================
                // 📏 CALCUL DISTANCE
                // ==========================
                async function calculateRoute() {
                    const fromLat = $("#from_lat").val();
                    const fromLng = $("#from_lng").val();
                    const toLat = $("#to_lat").val();
                    const toLng = $("#to_lng").val();

                    if (!fromLat || !toLat) return;

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

                        $("#base_price").val(data.price);

                        // ==========================
                        // 🗺️ TRACE DE LA ROUTE
                        // ==========================

                    } catch (error) {
                        console.error("Erreur lors du calcul de la route:", error);
                        $("#price-error").text("Erreur de calcul");
                        $("#total-price").text("Erreur de calcul");
                        $("#pricePreview").removeClass("hidden");
                    }
                }

                // ==========================
                // 🔄 GESTION ALLER-RETOUR ET MULTI-JOUR
                // ==========================

                // Afficher/masquer heure de retour
                function handleRoundTripChange() {
                    if ($('#round_trip').is(':checked')) {
                        $('#returnTimeWrapper').removeClass('hidden');
                        $('#return_time').attr('required', true);
                    } else {
                        $('#returnTimeWrapper').addClass('hidden');
                        $('#return_time').removeAttr('required').val('');
                    }
                }

                // Afficher/masquer jours de semaine (si multi-jour)
                function handleDaysChange() {
                    const days = parseInt($('#days').val()) || 1;
                    if (days > 1) {
                        $('#weekDaysWrapper').removeClass('hidden');
                    } else {
                        $('#weekDaysWrapper').addClass('hidden');
                        $('#week_days').val('');
                    }
                }

                // Event listeners
                $('#round_trip').on('change', handleRoundTripChange);
                $('#days').on('change', handleDaysChange);
                $('#days').on('input', handleDaysChange);

                // Initialiser l'affichage au chargement
                handleRoundTripChange();
                handleDaysChange();
            });
        </script>
    @endpush
@endsection
