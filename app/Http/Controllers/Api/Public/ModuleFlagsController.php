<?php

namespace App\Http\Controllers\Api\Public;

use App\Features\ModuleFeature;
use App\Http\Controllers\Controller;
use App\Services\ModuleService;
use Illuminate\Http\JsonResponse;

/**
 * Indique à l'app vitrine si l'inscription publique (recrutement livreur) doit
 * s'afficher. Appelée server-to-server ; réponse volontairement minimale.
 */
class ModuleFlagsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'can_register' => env('WEB_REGISTRATION_ENABLED', true)
                && ModuleService::isPublicActive(ModuleFeature::INSCRIPTION),
        ]);
    }
}
