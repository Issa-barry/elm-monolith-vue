<?php

namespace App\Http\Controllers\Divers;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;

class UnreadCountContactController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $count = ContactMessage::where('organization_id', auth()->user()->organization_id)
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }
}
