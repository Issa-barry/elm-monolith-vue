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

        $lignes = [
            [
                'role' => 'charge_salaire',
                'sens' => 'debit',
                'montant' => (float) $paiement->montant,
            ],
            [
                'role' => 'tresorerie',
                'sens' => 'credit',
                'montant' => (float) $paiement->montant,
                'moyen_paiement' => $paiement->mode_paiement,
            ],
        ];

        return $this->ecritures->comptabiliser(
            evenement: EvenementComptable::PAIEMENT_SALAIRE,
            source: $paiement,
            organizationId: $orgId,
            dateComptable: Carbon::parse($paiement->date_paiement ?? now()),
            libelle: 'Paiement salaire — '.($employe->nom_complet ?? '—'),
            lignes: $lignes,
            siteId: $employe->site_id,
        );
    }
}
