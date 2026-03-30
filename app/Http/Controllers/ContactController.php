<?php

namespace App\Http\Controllers;

use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'required|string|max:50',
            'message' => 'required|string|max:5000',
        ]);

        $contact = ContactMessage::create($validated);

        $to = config('mail.contact_to', config('mail.from.address'));
        Mail::to($to)->queue(new ContactMessageReceived($contact));

        return back()->with('success', 'Votre message a été envoyé. Nous vous répondrons rapidement.');
    }

    public function markRead(ContactMessage $contactMessage): JsonResponse
    {
        $contactMessage->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function unreadCount(): JsonResponse
    {
        $count = ContactMessage::where('organization_id', auth()->user()->organization_id)
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }
}
