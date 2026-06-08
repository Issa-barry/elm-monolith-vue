<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        /** @var User $user */
        $user = $request->user();

        $contact = ContactMessage::create([
            'name' => trim(($user->prenom ?? '').' '.($user->nom ?? '')) ?: null,
            'email' => $user->email,
            'phone' => $user->telephone,
            'message' => $request->string('message'),
            'organization_id' => $user->organization_id,
        ]);

        $to = config('mail.contact_to', config('mail.from.address'));
        try {
            Mail::to($to)->send(new ContactMessageReceived($contact));
        } catch (\Throwable) {
            // L'échec d'envoi email ne bloque pas la réponse
        }

        return response()->json(['message' => 'Votre message a été envoyé. Nous vous répondrons rapidement.']);
    }
}
