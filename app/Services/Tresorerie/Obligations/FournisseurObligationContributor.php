<?php

namespace App\Services\Tresorerie\Obligations;

use App\Enums\StatutFactureFournisseur;
use App\Models\FactureFournisseur;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Factures fournisseurs validées non soldées (ADR 0016, ADR 0024) : l'agence de la facture — celle
 * du bon de commande, explicitement responsable de son paiement — conserve leur reste dû avant toute
 * remise à la trésorerie principale ; la trésorerie principale la finance si nécessaire.
 *
 * Échéance d'une facture = sa date d'échéance, à défaut sa date de facture. Une facture est une
 * obligation du mois de son échéance (réglée « fin de mois ») ; échue et encore due, elle compte dans
 * les arriérés. Seules les factures constatées (validées, partiellement payées) ont une dette.
 */
class FournisseurObligationContributor implements ObligationContributor
{
    private const ECHEANCE = 'COALESCE(date_echeance, date_facture)';

    public function colonnes(): array
    {
        return ['fournisseurs'];
    }

    public function echeancesParColonne(): array
    {
        return ['fournisseurs' => 'fin_de_mois'];
    }

    public function collecter(string $organizationId, int $annee, int $mois, array &$besoin): void
    {
        $this->duMois($organizationId, $annee, $mois)
            ->get(['id', 'site_id', 'montant_ttc', 'montant_paye', 'statut'])
            ->each(function (FactureFournisseur $f) use (&$besoin) {
                ObligationAccumulator::ajouter($besoin, $f->site_id, 'fournisseurs', $f->resteDu(), (float) $f->montant_ttc);
            });
    }

    public function arrieres(string $organizationId, CarbonInterface $debutMois, array &$arrieres): void
    {
        FactureFournisseur::where('organization_id', $organizationId)
            ->whereIn('statut', [StatutFactureFournisseur::VALIDEE->value, StatutFactureFournisseur::PARTIELLEMENT_PAYEE->value])
            ->whereRaw(self::ECHEANCE.' < ?', [Carbon::instance($debutMois)->toDateString()])
            ->get(['id', 'site_id', 'montant_ttc', 'montant_paye', 'statut'])
            ->each(function (FactureFournisseur $f) use (&$arrieres) {
                ObligationAccumulator::ajouterArriere($arrieres, $f->site_id, $f->resteDu());
            });
    }

    public function detail(string $organizationId, int $annee, int $mois, ?string $siteId): array
    {
        return [
            'fournisseurs' => $this->duMois($organizationId, $annee, $mois)
                ->where('site_id', $siteId)
                ->with(['fournisseur.personne', 'fournisseur.entrepriseTierce'])
                ->get()
                ->filter(fn (FactureFournisseur $f) => $f->resteDu() > 0.0)
                ->map(fn (FactureFournisseur $f) => [
                    'id' => $f->id,
                    'nom' => trim(($f->fournisseurNom() ?? '—').' — '.$f->reference),
                    'montant_net' => (float) $f->montant_ttc,
                    'montant_paye' => (float) $f->montant_paye,
                    'montant_restant' => $f->resteDu(),
                    'statut' => $f->statut?->value,
                    'statut_label' => $f->statut?->label(),
                ])
                ->values()
                ->all(),
        ];
    }

    /** Factures constatées dont l'échéance tombe dans le mois. */
    private function duMois(string $organizationId, int $annee, int $mois): Builder
    {
        $debut = Carbon::create($annee, $mois, 1)->startOfMonth();

        return FactureFournisseur::where('organization_id', $organizationId)
            ->whereIn('statut', array_map(fn ($s) => $s->value, StatutFactureFournisseur::constatees()))
            ->whereRaw(self::ECHEANCE.' >= ?', [$debut->toDateString()])
            ->whereRaw(self::ECHEANCE.' <= ?', [$debut->copy()->endOfMonth()->toDateString()]);
    }
}
