<?php

namespace App\Http\Controllers\Api\Backoffice;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $site = $user->sites()
            ->wherePivot('is_default', true)
            ->first()
            ?? $user->sites()->first();

        return response()->json([
            'id' => $user->id,
            'prenom' => $user->prenom,
            'nom' => $user->nom,
            'telephone' => $user->telephone,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'is_active' => $user->is_active,
            'qr_payload' => $user->id,
            'site' => $this->resolveSite($user, $site),
        ]);
    }

    private function resolveSite(\App\Models\User $user, ?\App\Models\Site $site): ?array
    {
        if ($site) {
            return [
                'id' => $site->id,
                'nom' => $site->nom,
                'code' => $site->code,
                'ville' => $site->ville,
            ];
        }

        // Fallback : nom de l'organisation si le compte n'est rattaché à aucun site
        if ($user->organization_id) {
            $user->loadMissing('organization');
            if ($user->organization) {
                return [
                    'id' => $user->organization_id,
                    'nom' => $user->organization->name,
                    'code' => null,
                    'ville' => null,
                ];
            }
        }

        return null;
    }
}
