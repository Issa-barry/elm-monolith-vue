<?php

namespace App\Services;

use App\Enums\StatutDepense;
use App\Models\CashbackSolde;
use App\Models\CashbackTransaction;
use App\Models\CashbackVersement;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Depense;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CashbackService
{
    /**
     * Solde net encore versable au client. Les dépenses validées utilisent le flux commun
     * Depense et sont déduites une seule fois du cumul des gains validés du client.
     */
    public function montantDisponibleClient(string $organizationId, string $clientId): int
    {
        $gainsValides = (int) CashbackTransaction::where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->gains()
            ->whereIn('statut', [
                CashbackTransaction::STATUT_VALIDE,
                CashbackTransaction::STATUT_PARTIEL,
                CashbackTransaction::STATUT_VERSE,
            ])
            ->sum('montant');

        $verse = (int) CashbackVersement::whereHas('transaction', fn ($query) => $query
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId))
            ->sum('montant');

        $depenses = (int) Depense::where('organization_id', $organizationId)
            ->where('beneficiaire_type', 'client')
            ->where('beneficiaire_id', $clientId)
            ->where('statut', StatutDepense::VALIDE->value)
            ->sum('montant');

        return max(0, $gainsValides - $depenses - $verse);
    }

    /**
     * Déclenché au paiement complet de la facture (EncaissementVenteController) — moment
     * inchangé (CASHBACK-006, cf. docs/cashback.md), seule la FORMULE change (décision produit
     * du 28/08/2026, EN REMPLACEMENT du modèle à seuil d'achat global/gain fixe qui prévalait
     * jusque-là, cf. Parametre::CLE_CASHBACK_SEUIL_ACHAT/CLE_CASHBACK_MONTANT_GAIN, désormais
     * inertes — plus aucun code ne les lit).
     *
     * Cashback traité comme une commission propre au CLIENT (CASHBACK-002) : chaque client
     * éligible porte son propre montant fixe par pack (Client::cashback_montant_par_pack),
     * appliqué à la quantité éligible de CETTE vente — jamais de seuil cumulatif, jamais de
     * montant global d'organisation. Une vente sans le moindre pack éligible (produit non
     * fabricable, quantité nulle) ne génère aucun cashback, sans lever d'erreur.
     */
    public function processVente(CommandeVente $vente): void
    {
        if (! $vente->client_id || ! $vente->organization_id) {
            return;
        }

        $client = $vente->relationLoaded('client') ? $vente->client : Client::find($vente->client_id);

        if (! $client || ! $client->cashback_eligible) {
            return;
        }

        $montantUnitaire = (int) ($client->cashback_montant_par_pack ?? 0);
        if ($montantUnitaire <= 0) {
            return;
        }

        $quantiteEligible = $this->quantiteEligible($vente);
        if ($quantiteEligible <= 0) {
            return;
        }

        $orgId = $vente->organization_id;
        $montantTotal = $quantiteEligible * $montantUnitaire;

        DB::transaction(function () use ($vente, $orgId, $montantUnitaire, $quantiteEligible, $montantTotal) {
            $alreadyProcessed = CashbackTransaction::where('vente_id', $vente->id)
                ->where('type', CashbackTransaction::TYPE_GAIN)
                ->lockForUpdate()
                ->exists();

            if ($alreadyProcessed) {
                return;
            }

            $solde = CashbackSolde::lockForUpdate()->firstOrCreate(
                ['organization_id' => $orgId, 'client_id' => $vente->client_id],
                ['cumul_achats' => 0, 'cashback_en_attente' => 0, 'total_cashback_gagne' => 0, 'total_cashback_verse' => 0],
            );

            // Indicateur purement informatif désormais (widget "Cumul achats" de la fiche
            // client) — ne déclenche plus rien et n'est plus jamais remis à zéro (l'ancien
            // modèle à seuil le réinitialisait à chaque gain).
            $solde->cumul_achats += (int) $vente->total_commande;

            CashbackTransaction::create([
                'organization_id' => $orgId,
                'client_id' => $vente->client_id,
                'type' => CashbackTransaction::TYPE_GAIN,
                'montant' => $montantTotal,
                'montant_unitaire_snapshot' => $montantUnitaire,
                'quantite_eligible_snapshot' => $quantiteEligible,
                'montant_verse' => 0,
                'statut' => CashbackTransaction::STATUT_EN_ATTENTE,
                'vente_id' => $vente->id,
            ]);

            $solde->cashback_en_attente += $montantTotal;
            $solde->total_cashback_gagne += $montantTotal;
            $solde->save();
        });
    }

    /**
     * Quantité de packs ouvrant droit au cashback pour cette vente — réutilise le même repère
     * métier que la tarification par nature de client (PrixVenteNatureResolver::estFabricable()) :
     * seules les lignes de produits fabricables comptent, jamais un matériel/service facturé
     * accessoirement sur la même commande. Préfère la quantité réellement livrée quand un
     * chargement véhicule a eu lieu (cf. CommandeVenteLigne::quantite_livree), sinon la quantité
     * demandée (vente directe client, sans étape de chargement/livraison).
     */
    private function quantiteEligible(CommandeVente $vente): int
    {
        $vente->loadMissing('lignes.variante.produit.produitType');

        return (int) $vente->lignes
            ->filter(fn ($ligne) => $ligne->variante && PrixVenteNatureResolver::estFabricable($ligne->variante))
            ->sum(fn ($ligne) => (int) ($ligne->quantite_livree ?? $ligne->quantite_demandee));
    }

    /**
     * Valide un cashback (étape 1 — super_admin / admin_entreprise).
     */
    public function valider(CashbackTransaction $transaction, User $validePar, ?string $note = null): void
    {
        if (! $transaction->isEnAttente()) {
            throw new \InvalidArgumentException('Cette transaction ne peut pas être validée.');
        }

        $transaction->update([
            'statut' => CashbackTransaction::STATUT_VALIDE,
            'valide_le' => now(),
            'valide_par' => $validePar->id,
            'note' => $note,
        ]);
    }

    /**
     * Enregistre un versement (partiel ou total) sur une transaction validée.
     *
     * @throws \InvalidArgumentException si la transaction n'est pas versable
     */
    public function verser(
        CashbackTransaction $transaction,
        User $versePar,
        int $montant,
        string $modePaiement,
        string $dateVersement,
        ?string $note = null
    ): void {
        if (! $transaction->isVersable()) {
            throw new \InvalidArgumentException('Cette transaction doit être validée avant le versement.');
        }

        if ($montant <= 0 || $montant > $transaction->montant_restant) {
            throw new \InvalidArgumentException('Montant invalide.');
        }

        DB::transaction(function () use ($transaction, $versePar, $montant, $modePaiement, $dateVersement, $note) {
            $transactionVerrouillee = CashbackTransaction::whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $transactionVerrouillee->isVersable() || $montant > $transactionVerrouillee->montant_restant) {
                throw new \InvalidArgumentException('Montant invalide.');
            }

            CashbackTransaction::where('organization_id', $transactionVerrouillee->organization_id)
                ->where('client_id', $transactionVerrouillee->client_id)
                ->lockForUpdate()
                ->get(['id']);

            if ($montant > $this->montantDisponibleClient(
                $transactionVerrouillee->organization_id,
                $transactionVerrouillee->client_id,
            )) {
                throw new \InvalidArgumentException('Le montant dépasse le cashback net disponible après déduction des dépenses validées.');
            }

            // Crée le versement
            CashbackVersement::create([
                'cashback_transaction_id' => $transactionVerrouillee->id,
                'montant' => $montant,
                'mode_paiement' => $modePaiement,
                'date_versement' => $dateVersement,
                'note' => $note,
                'created_by' => $versePar->id,
            ]);

            // Recalcule statut et montant_verse
            $transactionVerrouillee->recalculStatut();

            // Met à jour le solde si entièrement versé
            if ($transactionVerrouillee->isVerse()) {
                $transactionVerrouillee->update(['verse_par' => $versePar->id]);

                CashbackSolde::where('organization_id', $transactionVerrouillee->organization_id)
                    ->where('client_id', $transactionVerrouillee->client_id)
                    ->decrement('cashback_en_attente', $transactionVerrouillee->montant);

                CashbackSolde::where('organization_id', $transactionVerrouillee->organization_id)
                    ->where('client_id', $transactionVerrouillee->client_id)
                    ->increment('total_cashback_verse', $transactionVerrouillee->montant);
            }
        });
    }
}
