<?php

namespace App\Services\Comptabilite;

use App\Enums\EvenementComptable;
use App\Models\PaiePaiement;
use App\Models\PieceComptable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Comptabilise la jambe trésorerie d'un paiement de salaire (PaiePaiement) —
 * avant le chantier Financement des agences, ce paiement n'avait AUCUNE
 * écriture dans compta_ecritures (seulement JournalTresorerie, cf. audit du
 * 2026-08-22). Sans cette écriture, le calcul du disponible par agence
 * (TresorerieDisponibiliteService) ignorerait les salaires payés localement et
 * surestimerait systématiquement la trésorerie réellement disponible.
 *
 * Volontairement minimal : pas d'engagement/dette préalable comptabilisé pour
 * la paie (contrairement aux fiches livreur/propriétaire via
 * FicheComptabilisationService) — retracer tout le cycle d'engagement de la
 * paie en comptabilité générale est un chantier séparé, hors périmètre ici.
 * Bloquant depuis la revue Codex du 2026-08-22 (même convention que
 * PaiementFichePaiement::booted()) : si la pièce échoue, le paiement de
 * salaire ne doit pas être enregistré non plus.
 */
class PaieComptabilisationService
{
    public function __construct(
        private readonly EcritureComptableService $ecritures,
    ) {}

    public function comptabiliserPaiement(PaiePaiement $paiement): ?PieceComptable
    {
        $ligne = $paiement->ligne;
        $orgId = $ligne?->periode?->organization_id;
        $employe = $ligne?->employe;

        if (! $orgId || ! $employe) {
            Log::error('Comptabilisation paiement salaire impossible : ligne/employé introuvable', [
                'paiement_id' => $paiement->id,
            ]);

            return null;
        }

        // Depuis l'ADR 0049, la sortie vise le compte du support réellement débité (caisse dédiée du
        // payeur, wallet, banque) : son solde baisse. Un paiement antérieur, sans support, garde la
        // résolution par compta_mappings — jamais reclassé.
        $support = $paiement->compteTresorerie;
        $ligneTresorerie = $support
            ? [
                'compte_comptable_id' => $support->compte_comptable_id,
                'journal_role' => 'tresorerie',
                'moyen_paiement' => $paiement->mode_paiement,
                'sens' => 'credit',
                'montant' => (float) $paiement->montant,
                'libelle' => 'Paiement salaire — '.($employe->nom_complet ?? '—')." — {$support->libelle}",
            ]
            : [
                'role' => 'tresorerie',
                'sens' => 'credit',
                'montant' => (float) $paiement->montant,
                'moyen_paiement' => $paiement->mode_paiement,
            ];

        $lignes = [
            [
                'role' => 'charge_salaire',
                'sens' => 'debit',
                'montant' => (float) $paiement->montant,
            ],
            $ligneTresorerie,
        ];

        return $this->ecritures->comptabiliser(
            evenement: EvenementComptable::PAIEMENT_SALAIRE,
            source: $paiement,
            organizationId: $orgId,
            dateComptable: Carbon::parse($paiement->date_paiement ?? now()),
            libelle: 'Paiement salaire — '.($employe->nom_complet ?? '—'),
            lignes: $lignes,
            // Agence d'où sort l'argent (site central pour un salarié sans agence) ; agence du salarié
            // pour un paiement antérieur.
            siteId: $paiement->site_id ?? $employe->site_id,
            createdBy: $paiement->created_by,
        );
    }
}
