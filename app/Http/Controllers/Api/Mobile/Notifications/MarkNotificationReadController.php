<?php

namespace App\Http\Controllers\Api\Mobile\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Mobile\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarkNotificationReadController extends Controller
{
    /**
     * 404 (jamais 403) si la notification n'appartient pas à l'utilisateur —
     * même convention que le reste de l'API Client (cf.
     * CommandesController::show()) : n'expose jamais si l'ID existe pour un
     * autre compte. Idempotent : un second appel sur une notification déjà
     * lue ne fait rien de plus.
     */
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $notification = $user->notifications()->where('id', $id)->firstOrFail();

        if (! $notification->read_at) {
            $notification->markAsRead();
        }

        return response()->json([
            'success' => true,
            'data' => new NotificationResource($notification->fresh()),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }
}
