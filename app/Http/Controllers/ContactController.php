<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;

class ContactController extends Controller
{
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
