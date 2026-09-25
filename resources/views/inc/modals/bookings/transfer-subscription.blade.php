{{-- Transfert d'un abonnement déjà pris à un autre agent. --}}
<div id="transferSubscriptionModal"
    class="fixed px-4 inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden items-center justify-center z-10">
    <div class="relative mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-2">Transférer l'abonnement</h3>
            <p class="text-sm text-gray-600 mb-4">
                Titulaire actuel : <strong>{{ $booking->subscriptionDriver?->user?->name ?? 'N/A' }}</strong>
            </p>

            <div class="mb-4">
                <label for="transferDriverSelect" class="block text-sm font-medium text-gray-700 mb-2">
                    Nouvel agent titulaire
                </label>
                <select id="transferDriverSelect"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">— Sélectionnez un agent —</option>
                    @foreach ($availableDrivers as $driverUser)
                        @if ($driverUser->driver && $driverUser->driver->id !== $booking->subscription_driver_id)
                            <option value="{{ $driverUser->driver->id }}">{{ $driverUser->name }} — {{ $driverUser->phone }}</option>
                        @endif
                    @endforeach
                </select>
            </div>

            <div class="text-xs text-gray-600 bg-indigo-50 rounded-lg p-3 mb-4 space-y-1">
                <p><i class="fas fa-check text-indigo-600 mr-1"></i> Passent au nouvel agent : les courses à venir, même
                    déjà acceptées, et celles révoquées que personne n'a reprises.</p>
                <p><i class="fas fa-lock text-gray-500 mr-1"></i> Ne bougent pas : les courses en cours ou terminées —
                    leurs revenus restent à l'agent qui les a faites.</p>
            </div>

            <div class="flex justify-end space-x-3">
                <button onclick="closeTransferModal()"
                    class="px-4 py-2 bg-gray-300 text-gray-800 rounded-md hover:bg-gray-400 transition">
                    Annuler
                </button>
                <button onclick="confirmTransfer()"
                    class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 transition">
                    Transférer
                </button>
            </div>
        </div>
    </div>
</div>
