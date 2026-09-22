<div id="pauseModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-800">
                Mettre en pause · <span id="pause-vehicle-label" class="text-yellow-600"></span>
            </h3>
            <button onclick="closePauseModal()" class="text-gray-400 hover:text-gray-600"><i
                    class="fas fa-times"></i></button>
        </div>

        <form id="pause-form" method="POST" class="space-y-4">
            @csrf
            <input type="hidden" name="vehicle_id" id="pause_vehicle_id">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date de début <span
                        class="text-red-500">*</span></label>
                <input type="date" name="start_date" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-500"
                    value="{{ date('Y-m-d') }}">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date de fin <span
                        class="text-gray-400 text-xs">(optionnel)</span></label>
                <input type="date" name="end_date"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Motif <span
                        class="text-red-500">*</span></label>
                <select name="reason_type" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    <option value="agent_leave">Pause agent</option>
                    <option value="agent_change">Changement d'agent</option>
                    <option value="technical">Technique / panne</option>
                    <option value="accident">Accident</option>
                    <option value="legal">Litige / légal</option>
                    <option value="other">Autre</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="reason_notes" rows="2"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-500"
                    placeholder="Précisions sur la pause..."></textarea>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closePauseModal()"
                    class="flex-1 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">Annuler</button>
                <button type="submit"
                    class="flex-1 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition font-semibold">
                    <i class="fas fa-pause mr-1"></i> Mettre en pause
                </button>
            </div>
        </form>
    </div>
</div>
