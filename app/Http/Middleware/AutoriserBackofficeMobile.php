<?php

namespace App\Http\Middleware;

use App\Models\CommissionLogistique;
use App\Models\TransfertLogistique;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorise une route de l'API back-office mobile. Le login standard délivre un jeton à tout compte
 * (ADR 0055) : `auth:sanctum` seul ne dit donc rien du droit d'agir ici.
 *
 * Le paramètre est une permission (`logistique.read`) ou, sur une route portant un transfert, une
 * capacité de TransfertLogistiquePolicy. L'organisation est contrôlée ici et non dans la policy :
 * Gate::before laisse passer le super administrateur sans lire la policy, or il reste limité à son
 * organisation. Ce middleware s'exécute avant les FormRequest : un compte refusé n'atteint jamais
 * la validation.
 */
class AutoriserBackofficeMobile
{
    public function handle(Request $request, Closure $next, string $capacite): Response
    {
        /** @var User $user */
        $user = $request->user();
        $transfert = $request->route('transfert');
        $commission = $request->route('commission');

        if (! $this->memeOrganisation($user, $transfert, $commission)
            || ! $user->can($capacite, $transfert ? [$transfert] : [])) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        return $next($request);
    }

    private function memeOrganisation(User $user, ?TransfertLogistique $transfert, ?CommissionLogistique $commission): bool
    {
        if ($user->organization_id === null) {
            return false;
        }

        $cible = $transfert ?? $commission;

        return $cible === null || $cible->organization_id === $user->organization_id;
    }
}
