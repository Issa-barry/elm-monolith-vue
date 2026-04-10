<?php

namespace App\Services;

use App\Models\CashbackSolde;
use App\Models\CashbackTransaction;
use App\Models\CommandeVente;
use App\Models\Parametre;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CashbackService
{
    /**
     * Appelé à la création d'une vente (via VenteObserver).
     *
     * - Incrémente le cumul d'achats du client.
     * - Si le seuil est atteint, crée une transaction "gain" et réinitialise le cumul.
     * - Idempotent sur la même vente : une seule transaction gain par vente_id.
     */
    public function processVente(CommandeVente $vente): void
    {
        // Seulement les ventes avec un client rattaché
        if (! $vente->client_id || ! $vente->organization_id) {
            return;
        }

        $orgId = $vente->organization_id;
        $montant = (int) $vente->total_commande;

        if ($montant <= 0) {
            return;
        }

        $seuil = Parametre::getCashbackSeuilAchat($orgId);
        $gain = Parametre::getCashbackMontantGain($orgId);

        // Seuil à 0 = module cashback mal configuré → on ne fait rien
        if ($seuil <= 0 || $gain <= 0) {
            return;
        }

        DB::transaction(function () use ($vente, $orgId, $montant, $seuil, $gain) {
            // Garde-fou anti-doublon : si un gain existe déjà pour cette vente, on skipe
            $alreadyProcessed = CashbackTransaction::where('vente_id', $vente->id)
                ->where('type', CashbackTransaction::TYPE_GAIN)
                ->lockForUpdate()
                ->exists();

            if ($alreadyProcessed) {
                return;
            }

            // Récupère ou crée le solde du client (avec verrou pour éviter les races)
            $solde = CashbackSolde::lockForUpdate()->firstOrCreate(
                ['organization_id' => $orgId, 'client_id' => $vente->client_id],
                [
                    'cumul_achats' => 0,
                    'cashback_en_attente' => 0,
                    'total_cashback_gagne' => 0,
                    'total_cashback_verse' => 0,
                ],
            );

            $solde->cumul_achats += $montant;

            if ($solde->cumul_achats >= $seuil) {
                // Franchissement du seuil → gain
                CashbackTransaction::create([
                    'organization_id' => $orgId,
                    'client_id' => $vente->client_id,
                    'type' => CashbackTransaction::TYPE_GAIN,
                    'montant' => $gain,
                    'statut' => CashbackTransaction::STATUT_EN_ATTENTE,
                    'vente_id' => $vente->id,
                ]);

                $solde->cashback_en_attente += $gain;
                $solde->total_cashback_gagne += $gain;
                $solde->cumul_achats = 0; // réinitialisation du compteur
            }

            $solde->save();
        });
    }

    /**
     * Marque une transaction "gain" comme versée.
     *
     * @throws \InvalidArgumentException si la transaction n'est pas en attente
     */
    public function verser(CashbackTransaction $transaction, User $versePar, ?string $note = null): void
    {
        if (! $transaction->isEnAttente()) {
            throw new \InvalidArgumentException('Cette transaction a déjà été versée.');
        }

        DB::transaction(function () use ($transaction, $versePar, $note) {
            $transaction->update([
                'statut' => CashbackTransaction::STATUT_VERSE,
                'verse_le' => now(),
                'verse_par' => $versePar->id,
                'note' => $note,
            ]);

            // Met à jour le solde du client
            CashbackSolde::where('organization_id', $transaction->organization_id)
                ->where('client_id', $transaction->client_id)
                ->decrement('cashback_en_attente', $transaction->montant);

            CashbackSolde::where('organization_id', $transaction->organization_id)
                ->where('client_id', $transaction->client_id)
                ->increment('total_cashback_verse', $transaction->montant);
        });
    }
}
