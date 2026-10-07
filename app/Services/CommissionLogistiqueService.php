<?php

namespace App\Services;

use App\Models\CommissionLogistiquePart;
use App\Models\TransfertLogistique;
use App\Models\VersementCommissionLogistique;
use App\Notifications\CommissionPayeeNotification;
use App\Services\Notification\BeneficiaireUserResolver;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\PushBodyFormatter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Décision produit du 03/09/2026 : le moteur générique (CommissionEnveloppeGenerator) est
 * désormais le SEUL moteur de commission logistique — vérifié sans aucun solde
 * `commission_logistique_parts` restant en production avant ce retrait. Les méthodes de
 * génération (genererPourTransfert/genererAutomatique/genererDepuisChargement) ont été retirées
 * avec CommissionTriggerService::estMigreVersMoteurGenerique(). Cette classe ne survit que pour
 * le paiement/historique d'éventuelles commissions déjà existantes (verser()) — jamais pour en
 * créer de nouvelles.
 */
class CommissionLogistiqueService
{
    /**
     * Versement legacy (retro-compat, utilisé depuis la page Transfert Show).
     */
    public static function verser(
        CommissionLogistiquePart $part,
        float $montant,
        string $dateVersement,
        string $modePaiement,
        ?string $note = null
    ): VersementCommissionLogistique {
        if ($part->isPaye()) {
            throw new InvalidArgumentException('Cette part est déjà entièrement versée.');
        }

        return DB::transaction(function () use ($part, $montant, $dateVersement, $modePaiement, $note) {
            $versement = VersementCommissionLogistique::create([
                'commission_logistique_part_id' => $part->id,
                'montant' => $montant,
                'date_versement' => $dateVersement,
                'mode_paiement' => $modePaiement,
                'note' => $note,
                'created_by' => Auth::id(),
            ]);

            $part->recalculStatut();

            self::notifierCommissionPayee($part, $montant, $modePaiement, $note);

            return $versement;
        });
    }

    /**
     * Agence qui paie la commission logistique d'un transfert : le site ACTUEL du véhicule
     * qui l'a exécuté (décision du 06/10/2026, même règle que les commissions de vente, cf.
     * CommissionEnveloppe::siteResponsableId()) — ni le site source ni le site destination.
     * Repli sur le site source si le véhicule n'est rattaché à aucun site.
     */
    public static function resolveSiteResponsable(TransfertLogistique $transfert): ?string
    {
        return $transfert->vehicule?->site_id ?? $transfert->site_source_id;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Notifie le bénéficiaire réel (livreur ou proprietaire — cf.
     * type_beneficiaire) d'un versement legacy de commission logistique.
     */
    private static function notifierCommissionPayee(CommissionLogistiquePart $part, float $montant, string $modePaiement, ?string $note): void
    {
        try {
            $beneficiaireId = $part->type_beneficiaire === 'livreur' ? $part->livreur_id : $part->proprietaire_id;
            $user = BeneficiaireUserResolver::resolve($part->type_beneficiaire, $beneficiaireId);
            $notif = new CommissionPayeeNotification($montant, $modePaiement, $note, 'commission_logistique_part', $part->id);
            $notifData = $user ? $notif->toArray($user) : null;

            NotificationDispatcher::send(
                $notif,
                [$user],
                'commissions',
                // Pas d'ID navigable ici (part de commission = ligne comptable interne, aucune
                // route de détail côté PWA) — le type seul suffit (cf. rapport Web Push 7/7).
                $notifData ? fn () => [
                    'title' => $notifData['titre'],
                    'body' => PushBodyFormatter::format($notifData),
                    'data' => ['type' => 'commission.paid'],
                ] : null,
            );
        } catch (Throwable $e) {
            Log::error('CommissionPayeeNotification (versement legacy) : envoi échoué', [
                'part_id' => $part->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
