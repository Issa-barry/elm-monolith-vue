<?php

namespace App\Services\Agents;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Models\CommandeVenteLigne;
use App\Models\FactureVente;
use App\Services\Rapports\RapportActiviteService;
use App\Support\Rapports\RapportPerimetre;
use App\Support\Situation\SituationVentesAgregats;
use Illuminate\Database\Eloquent\Builder;

/**
 * Onglet Situation de la fiche agent (docs/fiche-agent.md). Aucune règle propre : les chiffres
 * viennent du moteur du rapport d'activité (RapportActiviteService, ADR 0007), pour qu'un même
 * agent sur une même période ait les mêmes chiffres sur sa fiche et dans le rapport :
 *
 * - Activité commerciale : ventes CRÉÉES par l'agent (commandes_ventes.created_by), datées par la
 *   création de leur facture, valorisées en montant_net, hors annulées / annulées pour erreur de
 *   saisie / retournées ; encaissé et reste = état actuel de ces factures.
 * - Encaissements réalisés : encaissements SAISIS par l'agent (encaissements_ventes.created_by),
 *   datés par date_encaissement, quelle que soit la vente — c'est l'argent qu'il a reçu, à ne pas
 *   confondre avec l'« Encaissé » de ses ventes.
 *
 * Les graphiques (produits vendus, situation des paiements) portent sur exactement les ventes du
 * KPI et réutilisent les agrégats de la Situation véhicule (SituationVentesAgregats).
 */
class AgentSituationService
{
    private const STATUTS_COMMANDE_HORS_CA = [
        StatutCommandeVente::ANNULEE,
        StatutCommandeVente::ANNULEE_ERREUR_SAISIE,
        StatutCommandeVente::RETOURNEE,
    ];

    public function __construct(private readonly RapportActiviteService $rapport) {}

    /**
     * @return array{ventes: array, encaissements: array}
     */
    public function pourPerimetre(RapportPerimetre $p): array
    {
        return [
            'ventes' => $this->ventes($p),
            'encaissements' => $this->encaissements($p),
        ];
    }

    /**
     * @return array{kpis: array, produits: array, paiements: array}
     */
    private function ventes(RapportPerimetre $p): array
    {
        $resume = $this->rapport->ventes($p, 0)['resume'];

        $factures = $this->factures($p)
            ->with(['encaissements', 'commande.lignes.variante.produit'])
            ->orderByDesc('created_at')
            ->get();

        $lignes = $factures->flatMap(fn (FactureVente $f) => $f->commande?->lignes ?? collect())
            ->filter(fn ($l) => $l instanceof CommandeVenteLigne)
            ->values();

        return [
            'kpis' => [
                'ca_vendu' => (float) $resume['facture'],
                'encaisse' => (float) $resume['encaisse'],
                'reste_du' => (float) $resume['reste'],
                'nb_ventes' => (int) $resume['nombre'],
            ],
            'produits' => SituationVentesAgregats::produitsVendus($lignes),
            'paiements' => SituationVentesAgregats::paiements($factures),
        ];
    }

    /**
     * @return array{montant: float, nombre: int, par_moyen: list<array>}
     */
    private function encaissements(RapportPerimetre $p): array
    {
        $encaissements = $this->rapport->encaissements($p, 0);
        $total = (float) $encaissements['resume']['montant'];

        return [
            'montant' => $total,
            'nombre' => (int) $encaissements['resume']['nombre'],
            'par_moyen' => array_map(fn (array $m) => [
                'cle' => $m['cle'],
                'libelle' => $m['libelle'],
                'nombre' => $m['nombre'],
                'montant' => (float) $m['montant'],
                'pourcentage_montant' => SituationVentesAgregats::pourcentage((float) $m['montant'], $total),
            ], $encaissements['par_moyen']),
        ];
    }

    /**
     * Mêmes ventes que RapportActiviteService::ventes() (base + exclusions + date de facture).
     *
     * @return Builder<FactureVente>
     */
    private function factures(RapportPerimetre $p): Builder
    {
        return FactureVente::query()
            ->where('organization_id', $p->organizationId)
            ->where('statut_facture', '<>', StatutFactureVente::ANNULEE->value)
            ->whereBetween('created_at', [$p->debut(), $p->fin()])
            ->when($p->siteIds !== null, fn (Builder $q) => $q->whereIn('site_id', $p->siteIds))
            ->whereHas('commande', fn (Builder $q) => $q
                ->where('created_by', $p->agentId)
                ->whereNotIn('statut', array_map(fn (StatutCommandeVente $s) => $s->value, self::STATUTS_COMMANDE_HORS_CA)));
    }
}
