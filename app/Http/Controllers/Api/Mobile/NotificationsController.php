<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\NotificationsIndexRequest;
use App\Http\Resources\Api\Mobile\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Contrat unique pour la cloche de notifications — cf. NotificationResource
 * pour la normalisation. Pagination Laravel standard (comme
 * DepensesController/CommandesController) depuis le 27/08/2026, à la place de
 * l'ancien plafond fixe de 50 éléments non paginé.
 */
class NotificationsController extends Controller
{
    public function index(NotificationsIndexRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest()
            ->paginate($request->perPage())
            ->withQueryString();

        return NotificationResource::collection($notifications)
            ->additional(['unread_count' => $user->unreadNotifications()->count()]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'unread_count' => 0,
        ]);
    }

    /**
     * 404 (jamais 403) si la notification n'appartient pas à l'utilisateur —
     * même convention que le reste de l'API Client (cf.
     * CommandesController::show()) : n'expose jamais si l'ID existe pour un
     * autre compte. Idempotent : un second appel sur une notification déjà
     * lue ne fait rien de plus.
     */
    public function markRead(Request $request, string $id): JsonResponse
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
