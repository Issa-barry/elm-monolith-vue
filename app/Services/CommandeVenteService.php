<?php

namespace App\Services;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Parametre;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CommandeVenteService
{
    /**
     * Valide une commande en brouillon.
     * - Transition gardee atomique dans une transaction DB.
     * - Cree la facture (IMPAYEE) si absente (idempotent).
     * - Genere les commissions si le mode est "commande validee".
     */
    public function valider(CommandeVente $commande): void
    {
        abort_if(
            ! $commande->isBrouillon(),
            422,
            'Seule une commande en brouillon peut etre validee.'
        );

        DB::transaction(function () use ($commande) {
            if (! $commande->relationLoaded('facture')) {
                $commande->load('facture');
            }

            if (! $commande->facture) {
                FactureVente::create([
                    'organization_id' => $commande->organization_id,
                    'site_id' => $commande->site_id,
                    'vehicule_id' => $commande->vehicule_id,
                    'commande_vente_id' => $commande->id,
                    'montant_brut' => $commande->total_commande,
                    'montant_net' => $commande->total_commande,
                ]);
            }

            $commande->update([
                'statut' => StatutCommandeVente::EN_COURS,
                'validated_at' => now(),
            ]);
        });

        $modeCommission = Parametre::getVentesCommissionMode($commande->organization_id);
        $commande->loadMissing('vehicule');

        if (
            $modeCommission === Parametre::COMMISSION_MODE_COMMANDE_VALIDEE
            && $commande->vehicule_id
            && $commande->vehicule
        ) {
            CommissionGenerator::generateForCommandeIfMissing(
                $commande,
                null,
                Parametre::COMMISSION_MODE_COMMANDE_VALIDEE
            );
        }
    }

    /**
     * Annule une commande en cours.
     * - Verifie que la facture est IMPAYEE (ou absente).
     * - Annule commande + facture dans la meme transaction.
     */
    public function annuler(CommandeVente $commande, string $motif): void
    {
        abort_if($commande->isAnnulee(), 422, 'Cette commande est deja annulee.');

        abort_if(
            ! $commande->isEnCours(),
            422,
            'Seule une commande en cours peut etre annulee.'
        );

        if (! $commande->relationLoaded('facture')) {
            $commande->load('facture');
        }

        if ($commande->facture) {
            abort_if(
                $commande->facture->statut_facture !== StatutFactureVente::IMPAYEE,
                422,
                "Impossible d'annuler : la facture est deja encaissee partiellement ou soldee."
            );
        }

        DB::transaction(function () use ($commande, $motif) {
            $commande->update([
                'statut' => StatutCommandeVente::ANNULEE,
                'motif_annulation' => $motif,
                'annulee_at' => now(),
                'annulee_par' => Auth::id(),
            ]);

            if ($commande->facture) {
                $commande->facture->update([
                    'statut_facture' => StatutFactureVente::ANNULEE,
                ]);
            }
        });
    }
}
