<?php

namespace App\Services;

use App\Models\CommissionEnveloppePaiementItem;
use App\Models\CommissionEnveloppePart;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reporte un paiement de fiche (PaiementFichePaiement) sur les
 * CommissionEnveloppePart réellement soldées.
 *
 * Quelle que soit la vue depuis laquelle un paiement est visible (Commission
 * vente, Commission propriétaire, Fiches de paiement),
 * commission_enveloppe_parts.montant_verse est TOUJOURS la seule source de
 * vérité, jamais désynchronisée de paiement_fiche_paiements — une seule
 * chaîne de paiement, jamais de second circuit direct.
 */
class CommissionEnveloppePartAllocationService
{
    /**
     * Alloue FIFO (par enveloppe.earned_at) le montant d'un paiement de fiche
     * sur les CommissionEnveloppePart référencées par les lignes de cette
     * fiche. Aucune vérification de solde ici : PaiementFichePaiementController
     * a déjà plafonné le montant à `fiche->montant_restant`, lui-même dérivé
     * de ces mêmes parts (cf. calculerLivreursV2/calculerProprietairesV2).
     */
    public static function allouer(PaiementFiche $fiche, PaiementFichePaiement $paiement): void
    {
        $parts = self::partsPourFiche($fiche);

        if ($parts->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($parts, $paiement) {
            $restant = (float) $paiement->montant;

            foreach ($parts as $part) {
                if ($restant <= 0) {
                    break;
                }

                $partRestant = (float) $part->montant_restant;
                if ($partRestant <= 0) {
                    continue;
                }

                $alloue = min($restant, $partRestant);

                CommissionEnveloppePaiementItem::create([
                    'paiement_id' => $paiement->id,
                    'commission_enveloppe_part_id' => $part->id,
                    'amount_allocated' => round($alloue, 2),
                ]);

                // Recalcule montant_verse + statut sur la part, puis propage à l'enveloppe.
                $part->recalculStatut();

                $restant = round($restant - $alloue, 2);
            }
        });
    }

    /**
     * Annule l'allocation d'un paiement de fiche supprimé (PaiementFichePaiementController::destroy())
     * — supprime les items et recalcule chaque part touchée, pour que
     * montant_verse ne reste jamais gonflé après suppression du paiement.
     */
    public static function desallouer(PaiementFichePaiement $paiement): void
    {
        $items = $paiement->enveloppeItems()->with('part')->get();

        if ($items->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($items) {
            $parts = $items->pluck('part')->filter()->unique('id');

            CommissionEnveloppePaiementItem::whereIn('id', $items->pluck('id'))->delete();

            foreach ($parts as $part) {
                $part->recalculStatut();
            }
        });
    }

    /**
     * @return Collection<int, CommissionEnveloppePart>
     */
    public static function partsPourFiche(PaiementFiche $fiche): Collection
    {
        $partIds = $fiche->lignes()
            ->where('source_type', CommissionEnveloppePart::class)
            ->pluck('source_id');

        if ($partIds->isEmpty()) {
            return CommissionEnveloppePart::query()->whereRaw('1 = 0')->get();
        }

        return CommissionEnveloppePart::whereIn('id', $partIds)
            ->with('enveloppe')
            ->get()
            ->sortBy(fn (CommissionEnveloppePart $p) => $p->enveloppe->earned_at)
            ->values();
    }
}
