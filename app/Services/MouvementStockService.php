<?php

namespace App\Services;

use App\Models\MouvementStock;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use Illuminate\Support\Facades\Auth;

/**
 * Gestion idempotente des mouvements de stock liés aux transferts inter-sites.
 *
 * Timing :
 *  - Sortie source  → déclenché au passage en RECEPTION (produits quittés le site source)
 *  - Entrée destination → déclenché au passage en CLOTURE (produits comptés à destination)
 */
class MouvementStockService
{
    /**
     * Enregistre la sortie de stock du site source.
     * Idempotent : ne crée pas de doublon si déjà enregistré pour cette ligne.
     */
    public static function enregistrerSortieSource(TransfertLogistique $transfert): void
    {
        $transfert->loadMissing('lignes');
        $userId = Auth::id();

        foreach ($transfert->lignes as $ligne) {
            $quantite = $ligne->quantite_chargee ?? $ligne->quantite_demandee;

            self::creerSiAbsent([
                'organization_id' => $transfert->organization_id,
                'site_id' => $transfert->site_source_id,
                'produit_id' => $ligne->produit_id,
                'type' => 'sortie',
                'quantite' => $quantite,
                'source_type' => TransfertLigne::class,
                'source_id' => $ligne->id,
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * Enregistre l'entrée de stock au site destination.
     * Utilise quantite_recue (données réelles de réception).
     * Idempotent.
     */
    public static function enregistrerEntreeDestination(TransfertLogistique $transfert): void
    {
        $transfert->loadMissing('lignes');
        $userId = Auth::id();

        foreach ($transfert->lignes as $ligne) {
            // On comptabilise ce qui a réellement été reçu
            $quantite = $ligne->quantite_recue ?? $ligne->quantite_chargee ?? $ligne->quantite_demandee;

            self::creerSiAbsent([
                'organization_id' => $transfert->organization_id,
                'site_id' => $transfert->site_destination_id,
                'produit_id' => $ligne->produit_id,
                'type' => 'entree',
                'quantite' => $quantite,
                'source_type' => TransfertLigne::class,
                'source_id' => $ligne->id,
                'created_by' => $userId,
            ]);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Insère un mouvement uniquement s'il n'existe pas déjà
     * (unicité sur source_type + source_id + site_id + type).
     */
    private static function creerSiAbsent(array $data): void
    {
        $existe = MouvementStock::where('source_type', $data['source_type'])
            ->where('source_id', $data['source_id'])
            ->where('site_id', $data['site_id'])
            ->where('type', $data['type'])
            ->exists();

        if (! $existe) {
            MouvementStock::create($data);
        }
    }
}
