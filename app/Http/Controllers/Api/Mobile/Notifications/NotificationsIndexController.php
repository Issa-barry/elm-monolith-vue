<?php

namespace App\Http\Controllers\Api\Mobile\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\NotificationsIndexRequest;
use App\Http\Resources\Api\Mobile\NotificationResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Contrat unique pour la cloche de notifications — cf. NotificationResource pour la
 * normalisation. Pagination Laravel standard (comme DepensesController/CommandesController)
 * depuis le 27/08/2026, à la place de l'ancien plafond fixe de 50 éléments non paginé.
 */
class NotificationsIndexController extends Controller
{
    public function __invoke(NotificationsIndexRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest()
            ->paginate($request->perPage())
            ->withQueryString();

        return NotificationResource::collection($notifications)
            ->additional(['unread_count' => $user->unreadNotifications()->count()]);
    }
}
