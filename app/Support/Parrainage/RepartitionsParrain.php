<?php

namespace App\Support\Parrainage;

use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Nouvelles répartitions jointes au remplacement ou au retrait d'un parrain qui reçoit une part
 * des commissions (cf. PartageParrainService) — format et droit communs à StoreParrainController et
 * DestroyParrainController.
 */
final class RepartitionsParrain
{
    /** @return array<int, array<string, mixed>> */
    public static function valider(Request $request): array
    {
        $data = $request->validate([
            'repartitions' => ['nullable', 'array'],
            'repartitions.*.processus_code' => ['required', Rule::in(CommissionProcessusDefaults::PROCESSUS_REMUNERANT_LE_PARRAIN)],
            'repartitions.*.categorie_id' => ['required', 'string'],
            'repartitions.*.parts' => ['required', 'array', 'min:1'],
            'repartitions.*.parts.*.livreur_id' => ['required', 'string'],
            'repartitions.*.parts.*.montant_unitaire' => ['required', 'integer', 'min:0'],
            'repartitions.*.part_parrain' => ['nullable', 'integer', 'min:0'],
        ], [
            'repartitions.*.parts.*.montant_unitaire.integer' => 'Chaque montant doit être un entier GNF, sans décimales.',
            'repartitions.*.part_parrain.integer' => 'La part du parrain doit être un entier GNF, sans décimales.',
        ]);

        $repartitions = $data['repartitions'] ?? [];

        // Modifier la répartition des commissions de l'équipe : même droit que la modale Équipe.
        if (! empty($repartitions)) {
            abort_unless($request->user()->can('equipes-livraison.update'), 403);
        }

        return $repartitions;
    }
}
