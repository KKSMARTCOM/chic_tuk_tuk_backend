<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function profile()
    {
        $user = Auth::user();
        return view('pages.settings.profile', compact('user'));
    }

    public function settings()
    {
        $user = Auth::user();
        return view('pages.settings.index', compact('user'));
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            // Unicité parmi les comptes du même profil, comme l'index `(email|phone, profil)`.
            'email' => 'required|email|max:255|unique:users,email,' . $user->id . ',id,profil,' . $user->profil,
            'phone' => 'required|string|max:20|unique:users,phone,' . $user->id . ',id,profil,' . $user->profil,
            'adresse' => 'nullable|string|max:500',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($request->hasFile('profile_photo')) {
            // Supprimer l'ancienne photo si elle existe
            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }

            // Sauvegarder la nouvelle photo
            $path = $request->file('profile_photo')->store('profile-photos', 'public');
            $validated['profile_photo'] = $path;
        }

        $user->update($validated);

        return redirect()->route('profile')->with('success', 'Profil mis à jour avec succès');
    }

    public function updateNotificationSettings(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'email_notifications' => 'sometimes|boolean',
            'push_notifications' => 'sometimes|boolean',
        ]);

        $preferences = $user->notification_preferences ?? [];
        $preferences['email_notifications'] = $request->has('email_notifications') && $request->email_notifications;
        $preferences['push_notifications'] = $request->has('push_notifications') && $request->push_notifications;

        $user->update(['notification_preferences' => $preferences]);

        return redirect()->route('settings.settings')->with('success', 'Paramètres de notification mis à jour avec succès');
    }

    public function changePassword(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'current_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return redirect()->back()->with('error', 'Le mot de passe actuel est incorrect');
        }

        $user->update(['password' => Hash::make($validated['password'])]);

        return redirect()->route('settings.settings')->with('success', 'Mot de passe modifié avec succès');
    }
}
