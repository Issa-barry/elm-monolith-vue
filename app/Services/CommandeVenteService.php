<?php

namespace App\Services;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CommandeVenteService
{
    // ── Validation : BROUILLON → EN_COURS ────────────────────────────────────

    /**
     * Valide une commande en brouillon.
     * - Transition gardée atomique dans une transaction DB.
     * - Crée la facture (IMPAYEE) si absente (idempotent).
     * - Génère les commissions si un véhicule est associé.
     */
    public function valider(CommandeVente $commande): void
    {
        abort_if(
            ! $commande->isBrouillon(),
            422,
            'Seule une commande en brouillon peut être validée.'
        );

        DB::transaction(function () use ($commande) {
            // Idempotent : ne crée pas de doublon si la facture existe déjà
            if (! $commande->relationLoaded('facture')) {
                $commande->load('facture');
            }

            if (! $commande->facture) {
                FactureVente::create([
                    'organization_id'  => $commande->organization_id,
                    'site_id'          => $commande->site_id,
                    'vehicule_id'      => $commande->vehicule_id,
                    'commande_vente_id'=> $commande->id,
                    'montant_brut'     => $commande->total_commande,
                    'montant_net'      => $commande->total_commande,
                ]);
            }

            $commande->update([
                'statut'       => StatutCommandeVente::EN_COURS,
                'validated_at' => now(),
            ]);
        });

        // Génération commissions (hors transaction : non bloquante)
        $commande->loadMissing('vehicule');
        if ($commande->vehicule_id && $commande->vehicule) {
            CommissionGenerator::generateForCommandeIfMissing(
                $commande,
                null,
                'commande_validee'
            );
        }
    }

    // ── Annulation : EN_COURS → ANNULEE ──────────────────────────────────────

    /**
     * Annule une commande en cours.
     * - Vérifie que la facture est IMPAYEE (ou absente).
     * - Annule commande + facture dans la même transaction.
     */
    public function annuler(CommandeVente $commande, string $motif): void
    {
        abort_if($commande->isAnnulee(), 422, 'Cette commande est déjà annulée.');

        abort_if(
            ! $commande->isEnCours(),
            422,
            'Seule une commande en cours peut être annulée.'
        );

        if (! $commande->relationLoaded('facture')) {
            $commande->load('facture');
        }

        if ($commande->facture) {
            abort_if(
                $commande->facture->statut_facture !== StatutFactureVente::IMPAYEE,
                422,
                "Impossible d'annuler : la facture est déjà encaissée partiellement ou soldée."
            );
        }

        DB::transaction(function () use ($commande, $motif) {
            $commande->update([
                'statut'           => StatutCommandeVente::ANNULEE,
                'motif_annulation' => $motif,
                'annulee_at'       => now(),
                'annulee_par'      => Auth::id(),
            ]);

            if ($commande->facture) {
                $commande->facture->update([
                    'statut_facture' => StatutFactureVente::ANNULEE,
                ]);
            }
        });
    }
}
