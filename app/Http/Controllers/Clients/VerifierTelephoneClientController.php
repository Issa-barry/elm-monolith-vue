<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Services\TelephoneOwnerLookupService;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vérification live d'un numéro pendant la saisie (avant soumission), tous types de tiers
 * confondus — cf. TelephoneOwnerLookupService. `blocking` distingue les deux cas :
 *  - un autre CLIENT avec ce numéro reste bloquant (même règle stricte que
 *    ClientUniqueness::assertPhoneUniqueInOrg(), qui reste l'autorité finale à la soumission) ;
 *  - tout autre type (Fournisseur, Propriétaire, Livreur...) est purement informatif — partager
 *    un numéro entre rôles différents est un cas légitime dans ce projet (cf. docblock du
 *    service), jamais bloqué ici.
 */
class VerifierTelephoneClientController extends Controller
{
    use PhoneHandlerTrait;

    public function __invoke(Request $request): JsonResponse
    {
        $user = auth()->user();
        abort_unless($user->can('clients.create') || $user->can('clients.update'), 403);

        $data = $request->validate([
            'telephone' => ['required', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_phone_pays' => ['nullable', 'string'],
            'client_id' => ['nullable', 'string'],
        ]);

        $telephoneComplet = $this->buildInternationalPhone($data['telephone'], $data['code_phone_pays'] ?? null);
        $resultat = $telephoneComplet
            ? TelephoneOwnerLookupService::find($user->organization_id, $telephoneComplet, $data['client_id'] ?? null)
            : null;

        return response()->json([
            'found' => $resultat !== null,
            'blocking' => ($resultat['type'] ?? null) === 'client',
            'type' => $resultat['type'] ?? null,
            'label' => $resultat['label'] ?? null,
            'nom' => $resultat['nom'] ?? null,
        ]);
    }
}
