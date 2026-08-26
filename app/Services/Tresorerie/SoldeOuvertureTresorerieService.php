<?php

namespace App\Services\Tresorerie;

use App\Enums\EvenementComptable;
use App\Enums\StatutSoldeOuverture;
use App\Models\CompteTresorerie;
use App\Models\SoldeOuvertureTresorerie;
use App\Services\Comptabilite\EcritureComptableService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre puis valide le solde d'ouverture d'un support de trésorerie. La
 * validation seule produit une pièce comptable (débit compte du support /
 * crédit 109000 "Solde d'ouverture — contrepartie technique") : un brouillon
 * non validé n'a aucun impact sur la position de trésorerie calculée
 * (TresorerieDisponibiliteService ignore les soldes non validés).
 */
class SoldeOuvertureTresorerieService
{
    public function __construct(
        private readonly EcritureComptableService $ecritures,
    ) {}

    /** @param  array{date_situation:string,montant:float,justificatif_path?:?string,commentaire?:?string}  $data */
    public function enregistrer(string $organizationId, CompteTresorerie $compteTresorerie, array $data, ?string $createdBy): SoldeOuvertureTresorerie
    {
        if ($compteTresorerie->organization_id !== $organizationId) {
            throw new \InvalidArgumentException('Support de trésorerie hors organisation.');
        }

        if ($compteTresorerie->soldeOuverture()->exists()) {
            throw new \RuntimeException("Un solde d'ouverture existe déjà pour ce support de trésorerie.");
        }

        return SoldeOuvertureTresorerie::create([
            'organization_id' => $organizationId,
            'compte_tresorerie_id' => $compteTresorerie->id,
            'date_situation' => $data['date_situation'],
            'montant' => $data['montant'],
            'justificatif_path' => $data['justificatif_path'] ?? null,
            'commentaire' => $data['commentaire'] ?? null,
            'statut' => StatutSoldeOuverture::BROUILLON->value,
            'created_by' => $createdBy,
        ]);
    }

    public function valider(SoldeOuvertureTresorerie $solde, ?string $userId): SoldeOuvertureTresorerie
    {
        return DB::transaction(function () use ($solde, $userId) {
            $verrouille = SoldeOuvertureTresorerie::whereKey($solde->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut === StatutSoldeOuverture::VALIDE) {
                return $verrouille;
            }

            // Un solde d'ouverture à 0 GNF n'est pas un événement comptable (rien à
            // débiter/créditer) — on valide sans pièce, ce qui reste suffisant pour que
            // TresorerieDisponibiliteService/FinancementAgenceService considèrent ce
            // support comme ayant une position fiable (cf. positionFiable()).
            if ((float) $verrouille->montant === 0.0) {
                $verrouille->update([
                    'statut' => StatutSoldeOuverture::VALIDE->value,
                    'valide_by' => $userId,
                    'valide_at' => now(),
                ]);

                return $verrouille->fresh();
            }

            $compteTresorerie = $verrouille->compteTresorerie;

            $piece = $this->ecritures->comptabiliser(
                evenement: EvenementComptable::SOLDE_OUVERTURE_TRESORERIE,
                source: $verrouille,
                organizationId: $verrouille->organization_id,
                dateComptable: Carbon::parse($verrouille->date_situation),
                libelle: "Solde d'ouverture — {$compteTresorerie->libelle}",
                lignes: [
                    [
                        'compte_comptable_id' => $compteTresorerie->compte_comptable_id,
                        'sens' => 'debit',
                        'montant' => (float) $verrouille->montant,
                    ],
                    [
                        'role' => 'contrepartie_ouverture',
                        'sens' => 'credit',
                        'montant' => (float) $verrouille->montant,
                    ],
                ],
                siteId: $compteTresorerie->site_id,
                createdBy: $userId,
            );

            $verrouille->update([
                'statut' => StatutSoldeOuverture::VALIDE->value,
                'valide_by' => $userId,
                'valide_at' => now(),
                'piece_comptable_id' => $piece->id,
            ]);

            return $verrouille->fresh();
        });
    }
}
