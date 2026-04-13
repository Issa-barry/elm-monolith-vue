<?php

namespace App\Services;

use App\Models\CashbackSolde;
use App\Models\CashbackTransaction;
use App\Models\CashbackVersement;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Parametre;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CashbackService
{
    /**
     * Déclenché au paiement complet de la facture (EncaissementVenteController).
     * Incrémente le cumul et crée un gain si le seuil est atteint.
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

        $orgId = $vente->organization_id;
        $montant = (int) $vente->total_commande;

        if ($montant <= 0) {
            return;
        }

        $seuil = Parametre::getCashbackSeuilAchat($orgId);
        $gain = Parametre::getCashbackMontantGain($orgId);

        if ($seuil <= 0 || $gain <= 0) {
            return;
        }

        DB::transaction(function () use ($vente, $orgId, $montant, $seuil, $gain) {
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

            $solde->cumul_achats += $montant;

            if ($solde->cumul_achats >= $seuil) {
                CashbackTransaction::create([
                    'organization_id' => $orgId,
                    'client_id' => $vente->client_id,
                    'type' => CashbackTransaction::TYPE_GAIN,
                    'montant' => $gain,
                    'montant_verse' => 0,
                    'statut' => CashbackTransaction::STATUT_EN_ATTENTE,
                    'vente_id' => $vente->id,
                ]);

                $solde->cashback_en_attente += $gain;
                $solde->total_cashback_gagne += $gain;
                $solde->cumul_achats = 0;
            }

            $solde->save();
        });
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
            // Crée le versement
            CashbackVersement::create([
                'cashback_transaction_id' => $transaction->id,
                'montant' => $montant,
                'mode_paiement' => $modePaiement,
                'date_versement' => $dateVersement,
                'note' => $note,
                'created_by' => $versePar->id,
            ]);

            // Recalcule statut et montant_verse
            $transaction->recalculStatut();

            // Met à jour le solde si entièrement versé
            if ($transaction->isVerse()) {
                $transaction->update(['verse_par' => $versePar->id]);

                CashbackSolde::where('organization_id', $transaction->organization_id)
                    ->where('client_id', $transaction->client_id)
                    ->decrement('cashback_en_attente', $transaction->montant);

                CashbackSolde::where('organization_id', $transaction->organization_id)
                    ->where('client_id', $transaction->client_id)
                    ->increment('total_cashback_verse', $transaction->montant);
            }
        });
    }
}
