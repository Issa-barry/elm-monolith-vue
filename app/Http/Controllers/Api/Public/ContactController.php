<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use App\Services\ModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Appelée server-to-server par l'app vitrine (formulaire de contact public),
 * jamais directement par un navigateur — voir VerifyVitrineServiceToken.
 */
class ContactController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'required|string|max:50',
            'message' => 'required|string|max:5000',
        ]);

        $contact = ContactMessage::create([
            ...$validated,
            'organization_id' => ModuleService::publicOrganization()?->id,
        ]);

        $to = config('mail.contact_to', config('mail.from.address'));
        try {
            Mail::to($to)->send(new ContactMessageReceived($contact));
        } catch (\Throwable) {
            // L'échec d'envoi email ne bloque pas la réponse.
        }

        return response()->json(['message' => 'Votre message a été envoyé. Nous vous répondrons rapidement.']);
    }
}
