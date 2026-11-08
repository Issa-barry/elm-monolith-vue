<?php

namespace App\Http\Controllers\Proprietaires;

use App\Http\Controllers\Controller;
use App\Models\Proprietaire;
use App\Services\Contacts\ProprietaireEntreprise;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * « Rechercher » du formulaire Propriétaire, type entreprise (ADR 0060, lot 5) : entreprises de
 * l'organisation qui ont le même téléphone, le même NIF ou la même raison sociale normalisée.
 * Lecture seule ; réservé à qui peut créer un propriétaire (ou modifier celui désigné). Seules les
 * entreprises visibles du lecteur sont renvoyées ; un téléphone ou un NIF d'une entreprise non
 * visible renvoie « indisponible » avec un message neutre, sans aucune donnée (D6).
 */
class EntreprisesCorrespondantesController extends Controller
{
    use PhoneHandlerTrait;

    public function __invoke(Request $request, ProprietaireEntreprise $entreprises): JsonResponse
    {
        $user = $request->user();
        abort_if($user->organization_id === null, 403);

        $data = $request->validate([
            'raison_sociale' => ['nullable', 'string', 'max:200'],
            'telephone_entreprise' => ['nullable', 'string', 'max:30'],
            'identifiant_fiscal' => ['nullable', 'string', 'max:50'],
            'code_pays' => ['nullable', Rule::in(array_keys(static::supportedPays()))],
            'proprietaire' => ['nullable', 'string', 'max:26'],
        ]);

        $proprietaire = null;
        if (! empty($data['proprietaire'])) {
            $proprietaire = Proprietaire::where('organization_id', $user->organization_id)->find($data['proprietaire']);
            abort_if($proprietaire === null, 404);
            $this->authorize('update', $proprietaire);
        } else {
            $this->authorize('create', Proprietaire::class);
        }

        $data = $this->resolveCountryData($data);
        $brut = trim((string) ($data['telephone_entreprise'] ?? ''));
        $telephone = $brut !== '' ? $this->buildInternationalPhone($brut, $data['code_phone_pays'] ?? null) : null;

        $vue = $entreprises->correspondancesPour($user, [
            'raison_sociale' => $data['raison_sociale'] ?? null,
            'telephone' => $telephone,
            'identifiant_fiscal' => $data['identifiant_fiscal'] ?? null,
        ], $proprietaire?->id);

        if ($vue['bloque'] !== null) {
            return response()->json([
                'statut' => 'indisponible',
                'champ' => $vue['bloque']['champ'],
                'message' => $vue['bloque']['message'],
                'correspondances' => [],
            ]);
        }

        return response()->json([
            'statut' => $vue['correspondances'] === [] ? 'aucune' : 'correspondances',
            'correspondances' => $vue['correspondances'],
        ]);
    }
}
