<?php

namespace App\Services\Commission;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionAncrageType;
use App\Models\CommissionGroupe;
use App\Models\CommissionGroupeMembre;
use App\Models\Vehicule;
use Carbon\Carbon;

/**
 * Pont Phase 1 uniquement : synchronise le CommissionGroupe "équipe livraison"
 * d'un véhicule depuis sa configuration EquipeLivraison/EquipeLivreur actuelle
 * (l'ancien schéma reste la source de vérité tant que la Phase 2 n'introduit pas
 * de vrais barèmes organisation-wide indépendants du véhicule).
 *
 * Rescale les taux des livreurs pour qu'ils totalisent 100 % ENTRE EUX (le
 * propriétaire, désormais une cible directe séparée, n'entre plus dans ce
 * partage) — la conception cible §0.1.2 démontre algébriquement que ce
 * rescale, combiné à une enveloppe "livraison" dimensionnée à
 * margeOperation × (100 - tauxProprietaire) / 100, reproduit exactement les
 * mêmes montants finaux par livreur que l'ancien moteur à pot unique.
 */
class CommissionGroupeSyncService
{
    public static function syncEquipeLivraisonVehicule(Vehicule $vehicule): ?CommissionGroupe
    {
        $vehicule->loadMissing('equipe.membres.livreur');
        $equipe = $vehicule->equipe;

        if (! $equipe) {
            return null;
        }

        $membres = $equipe->membres;
        $sommeLivreurs = (float) $membres->sum('taux_commission');

        $groupe = CommissionGroupe::firstOrCreate(
            [
                'organization_id' => $vehicule->organization_id,
                'type' => CommissionGroupe::TYPE_EQUIPE_LIVRAISON,
                'ancrage_type' => CommissionAncrageType::VEHICULE->value,
                'ancrage_id' => $vehicule->id,
            ],
            ['statut' => CommissionActivationStatut::ACTIF->value]
        );

        $rescaled = [];
        foreach ($membres as $m) {
            $rescaled[$m->livreur_id] = $sommeLivreurs > 0
                ? round((float) $m->taux_commission / $sommeLivreurs * 100, 2)
                : 0.0;
        }

        $today = Carbon::today();
        $actifs = $groupe->membresActifsA($today)->keyBy('beneficiaire_id');

        $inchange = $actifs->count() === count($rescaled) && collect($rescaled)->every(
            fn ($pct, $livreurId) => $actifs->has($livreurId)
                && abs((float) $actifs[$livreurId]->part_pourcentage - $pct) < 0.005
        );

        if ($inchange) {
            return $groupe;
        }

        foreach ($actifs as $m) {
            if ($m->effective_from->isSameDay($today)) {
                // Déjà ouvert aujourd'hui (deuxième sync le même jour) : on remplace
                // plutôt que de créer une ligne avec effective_to < effective_from.
                $m->delete();

                continue;
            }
            $m->update(['effective_to' => $today->copy()->subDay()]);
        }

        foreach ($rescaled as $livreurId => $pct) {
            CommissionGroupeMembre::create([
                'groupe_id' => $groupe->id,
                'beneficiaire_type' => CommissionGroupeMembre::TYPE_LIVREUR,
                'beneficiaire_id' => $livreurId,
                'part_pourcentage' => $pct,
                'effective_from' => $today,
                'effective_to' => null,
            ]);
        }

        return $groupe->fresh();
    }
}
