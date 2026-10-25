<?php

namespace App\Services\Ventes\ControleEncaissements;

use App\Enums\EvenementComptable;
use App\Enums\ModePaiement;
use App\Enums\StatutPieceComptable;
use App\Enums\TypeAnomalieEncaissement;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\PieceComptable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Règles de détection du contrôle des encaissements (docs/controle-encaissements.md, ADR 0036).
 * Lecture seule : produit des « constats » (tableaux), jamais d'écriture.
 *
 * - Encaissé net = somme des encaissements − remboursements (`factures_ventes.montant_rembourse`).
 *   Un encaissement supprimé n'existe plus (sa pièce est contrepassée) : il n'est jamais compté.
 * - Écart d'une facture (une seule anomalie par facture, clé `ecart:{facture}`) quand l'encaissé net
 *   dépasse le facturé : double encaissement si deux encaissements identiques et rapprochés
 *   expliquent le surplus, trop-perçu si c'est une précommande, surplus encaissé sinon.
 * - Doublon suspect : deux encaissements identiques (montant, mode, agent) à moins de
 *   FENETRE_DOUBLON_SECONDES d'écart sur une facture NON dépassée. Deux paiements partiels de
 *   montants, modes ou agents différents ne le sont jamais.
 * - Incohérence comptable : encaissement sans pièce `encaissement_vente_recu` validée, ou pièce
 *   validée dont l'encaissement n'existe plus (suppression sans contrepassation).
 * - Référence Mobile Money réutilisée : même référence normalisée sur plusieurs encaissements.
 *
 * Constat : ['cle', 'type', 'facture_vente_id', 'commande_vente_id', 'site_id', 'montant_facture',
 * 'montant_encaisse_net', 'surplus', 'agent_ids', 'details'].
 */
class DetecteurAnomaliesEncaissement
{
    public const FENETRE_DOUBLON_SECONDES = 120;

    private const TOLERANCE = 0.004;

    /** @return list<array<string, mixed>> */
    public function pourOrganisation(string $organizationId): array
    {
        $factureIds = $this->facturesEnEcart($organizationId)
            ->merge($this->facturesAvecEncaissementsIdentiques($organizationId))
            ->unique()
            ->values();

        $constats = [];
        foreach ($factureIds->chunk(200) as $lot) {
            FactureVente::query()
                ->whereIn('id', $lot)
                ->with(['commande:id,reference,est_precommande', 'encaissements.creator', 'encaissements.siteEncaissement:id,nom', 'encaissements.compteTresorerie:id,libelle'])
                ->get()
                ->each(function (FactureVente $f) use (&$constats) {
                    array_push($constats, ...$this->constatsFacture($f));
                });
        }

        array_push($constats, ...$this->encaissementsSansEcriture($organizationId));
        array_push($constats, ...$this->ecrituresOrphelines($organizationId));
        array_push($constats, ...$this->referencesMobileMoneyReutilisees($organizationId));

        return $constats;
    }

    /**
     * Contrôle immédiat d'une facture (après un encaissement, une suppression ou un remboursement).
     *
     * @param  list<string>  $encaissementsSupprimes  encaissements que l'opération vient de supprimer
     * @return list<array<string, mixed>>
     */
    public function pourFacture(FactureVente $facture, array $encaissementsSupprimes = []): array
    {
        $facture->load(['commande:id,reference,est_precommande', 'encaissements.creator', 'encaissements.siteEncaissement:id,nom', 'encaissements.compteTresorerie:id,libelle']);

        return [
            ...$this->constatsFacture($facture),
            ...$this->encaissementsSansEcriture($facture->organization_id, $facture->id),
            ...$this->ecrituresOrphelines($facture->organization_id, $encaissementsSupprimes, $facture),
        ];
    }

    // ── Écart et doublons d'une facture ──────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function constatsFacture(FactureVente $facture): array
    {
        $encaissements = $facture->encaissements->sortBy('created_at')->values();
        if ($encaissements->isEmpty()) {
            return [];
        }

        $precommande = (bool) $facture->commande?->est_precommande;
        $encaisseNet = round((float) $encaissements->sum('montant') - $facture->montantRembourse(), 2);
        // Facture annulée : plus rien n'est facturé ; seule une précommande annulée garde un
        // trop-perçu à rendre (FactureVente::tropPercu()). Hors précommande, l'annulation
        // exceptionnelle traite déjà les encaissements.
        if ($facture->isAnnulee() && ! $precommande) {
            return [];
        }
        $netFacture = $facture->isAnnulee() ? 0.0 : round((float) $facture->montant_net, 2);
        $surplus = round($encaisseNet - $netFacture, 2);
        $paire = $this->paireIdentique($encaissements);

        if ($surplus > self::TOLERANCE) {
            $type = match (true) {
                $paire !== null && $surplus + self::TOLERANCE >= (float) $paire[1]->montant => TypeAnomalieEncaissement::DOUBLE_ENCAISSEMENT,
                $precommande => TypeAnomalieEncaissement::TROP_PERCU_PRECOMMANDE,
                default => TypeAnomalieEncaissement::SURPLUS_ENCAISSE,
            };

            return [$this->constat(
                cle: 'ecart:'.$facture->id,
                type: $type,
                facture: $facture,
                encaissements: $encaissements,
                encaisseNet: $encaisseNet,
                surplus: $surplus,
                suspects: $paire ? [$paire[0]->id, $paire[1]->id] : [],
                explication: match ($type) {
                    TypeAnomalieEncaissement::DOUBLE_ENCAISSEMENT => 'Deux encaissements identiques de '.self::gnf((float) $paire[1]->montant)
                        .' à '.self::ecartSecondes($paire[0], $paire[1]).' s d\'intervalle font dépasser le facturé de '.self::gnf($surplus).'.',
                    TypeAnomalieEncaissement::TROP_PERCU_PRECOMMANDE => 'Le client a versé '.self::gnf($surplus).' de plus que ce qui lui est facturé.',
                    default => 'L\'encaissé net dépasse le facturé de '.self::gnf($surplus).'.',
                },
            )];
        }

        if ($paire !== null) {
            $ids = [$paire[0]->id, $paire[1]->id];
            sort($ids);

            return [$this->constat(
                cle: 'doublon:'.$facture->id.':'.implode(':', $ids),
                type: TypeAnomalieEncaissement::DOUBLON_SUSPECT,
                facture: $facture,
                encaissements: $encaissements,
                encaisseNet: $encaisseNet,
                surplus: 0.0,
                suspects: [$paire[0]->id, $paire[1]->id],
                explication: 'Deux encaissements identiques de '.self::gnf((float) $paire[1]->montant).' par le même agent à '
                    .self::ecartSecondes($paire[0], $paire[1]).' s d\'intervalle, sans dépasser le facturé.',
            )];
        }

        return [];
    }

    /**
     * Les deux encaissements les plus rapprochés ayant même montant, même mode et même auteur, à
     * moins de FENETRE_DOUBLON_SECONDES d'écart.
     *
     * @param  Collection<int, EncaissementVente>  $encaissements  triés par date de création
     * @return array{0: EncaissementVente, 1: EncaissementVente}|null
     */
    private function paireIdentique(Collection $encaissements): ?array
    {
        $meilleure = null;
        $ecartMin = PHP_INT_MAX;

        $groupes = $encaissements->groupBy(fn (EncaissementVente $e) => implode('|', [
            number_format((float) $e->montant, 2, '.', ''),
            $e->mode_paiement?->value,
            $e->created_by,
        ]));

        foreach ($groupes as $groupe) {
            for ($i = 1; $i < $groupe->count(); $i++) {
                $avant = $groupe[$i - 1];
                $apres = $groupe[$i];
                $ecart = self::ecartSecondes($avant, $apres);
                if ($ecart <= self::FENETRE_DOUBLON_SECONDES && $ecart < $ecartMin) {
                    $meilleure = [$avant, $apres];
                    $ecartMin = $ecart;
                }
            }
        }

        return $meilleure;
    }

    /** @return Collection<int, string> */
    private function facturesEnEcart(string $organizationId): Collection
    {
        return DB::table('factures_ventes as f')
            ->join('encaissements_ventes as e', 'e.facture_vente_id', '=', 'f.id')
            ->where('f.organization_id', $organizationId)
            ->whereNull('f.deleted_at')
            ->groupBy('f.id', 'f.montant_net', 'f.montant_rembourse', 'f.statut_facture')
            ->havingRaw("SUM(e.montant) - COALESCE(f.montant_rembourse, 0) > (CASE WHEN f.statut_facture = 'annulee' THEN 0 ELSE f.montant_net END) + ?", [self::TOLERANCE])
            ->pluck('f.id');
    }

    /** @return Collection<int, string> */
    private function facturesAvecEncaissementsIdentiques(string $organizationId): Collection
    {
        return DB::table('encaissements_ventes as e')
            ->join('factures_ventes as f', 'f.id', '=', 'e.facture_vente_id')
            ->where('f.organization_id', $organizationId)
            ->whereNull('f.deleted_at')
            ->groupBy('e.facture_vente_id', 'e.montant', 'e.mode_paiement', 'e.created_by')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('e.facture_vente_id');
    }

    // ── Comptabilité ─────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function encaissementsSansEcriture(string $organizationId, ?string $factureId = null): array
    {
        $morph = (new EncaissementVente)->getMorphClass();

        $encaissements = EncaissementVente::query()
            ->select('encaissements_ventes.*')
            ->join('factures_ventes as f', 'f.id', '=', 'encaissements_ventes.facture_vente_id')
            ->where('f.organization_id', $organizationId)
            ->whereNull('f.deleted_at')
            ->when($factureId, fn ($q) => $q->where('f.id', $factureId))
            ->where('encaissements_ventes.montant', '>', 0)
            ->whereNotExists(fn ($q) => $q->from('compta_pieces as p')
                ->whereColumn('p.source_id', 'encaissements_ventes.id')
                ->where('p.source_type', $morph)
                ->where('p.type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)
                ->where('p.statut', StatutPieceComptable::VALIDEE->value))
            ->with(['facture.commande:id,reference,est_precommande', 'creator', 'siteEncaissement:id,nom', 'compteTresorerie:id,libelle'])
            ->get();

        if ($encaissements->isEmpty()) {
            return [];
        }

        $pieces = PieceComptable::query()
            ->where('source_type', $morph)
            ->whereIn('source_id', $encaissements->pluck('id'))
            ->where('type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)
            ->get()
            ->keyBy('source_id');

        return $encaissements->map(function (EncaissementVente $e) use ($pieces) {
            $piece = $pieces->get($e->id);
            $facture = $e->facture;

            return $this->constat(
                cle: 'compta:encaissement:'.$e->id,
                type: TypeAnomalieEncaissement::INCOHERENCE_COMPTABLE,
                facture: $facture,
                encaissements: collect([$e]),
                encaisseNet: null,
                surplus: 0.0,
                suspects: [$e->id],
                explication: $piece
                    ? 'L\'encaissement de '.self::gnf((float) $e->montant).' existe, mais sa pièce comptable '.$piece->numero.' est « '.$piece->statut->value.' ».'
                    : 'L\'encaissement de '.self::gnf((float) $e->montant).' n\'a aucune écriture comptable validée : il ne figure pas en trésorerie.',
                avecMontantsFacture: false,
            );
        })->all();
    }

    /**
     * Pièces validées d'encaissements qui n'existent plus : l'argent compte encore en caisse alors
     * que l'encaissement a disparu.
     *
     * @param  list<string>  $parmi  limite le contrôle à ces encaissements (contrôle immédiat)
     * @return list<array<string, mixed>>
     */
    private function ecrituresOrphelines(string $organizationId, ?array $parmi = null, ?FactureVente $facture = null): array
    {
        if ($parmi === []) {
            return [];
        }
        $morph = (new EncaissementVente)->getMorphClass();

        return PieceComptable::query()
            ->where('organization_id', $organizationId)
            ->where('source_type', $morph)
            ->where('type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)
            ->where('statut', StatutPieceComptable::VALIDEE->value)
            ->when($parmi !== null, fn ($q) => $q->whereIn('source_id', $parmi))
            ->whereNotExists(fn ($q) => $q->from('encaissements_ventes as e')->whereColumn('e.id', 'compta_pieces.source_id'))
            ->with('lignes')
            ->get()
            ->map(function (PieceComptable $piece) use ($organizationId, $facture) {
                $montant = round((float) $piece->lignes->sum('debit'), 2);
                $siteId = $piece->lignes->firstWhere('site_id', '!=', null)?->site_id;

                return [
                    'cle' => 'compta:piece:'.$piece->id,
                    'type' => TypeAnomalieEncaissement::INCOHERENCE_COMPTABLE,
                    'organization_id' => $organizationId,
                    'facture_vente_id' => $facture?->id,
                    'commande_vente_id' => $facture?->commande_vente_id,
                    'site_id' => $facture?->site_id ?? $siteId,
                    'montant_facture' => null,
                    'montant_encaisse_net' => null,
                    'surplus' => 0.0,
                    'agent_ids' => array_values(array_filter([$piece->created_by])),
                    'details' => [
                        'explication' => 'La pièce '.$piece->numero.' ('.self::gnf($montant).') est toujours validée alors que son encaissement a été supprimé : le montant compte encore en trésorerie.',
                        'piece_id' => $piece->id,
                        'piece_numero' => $piece->numero,
                        'encaissement_supprime_id' => $piece->source_id,
                        'encaissements' => [],
                        'suspects' => [],
                    ],
                ];
            })->all();
    }

    // ── Références Mobile Money ──────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function referencesMobileMoneyReutilisees(string $organizationId): array
    {
        $doublons = DB::table('encaissements_ventes as e')
            ->join('factures_ventes as f', 'f.id', '=', 'e.facture_vente_id')
            ->where('f.organization_id', $organizationId)
            ->whereNull('f.deleted_at')
            ->where('e.mode_paiement', ModePaiement::MOBILE_MONEY->value)
            ->whereNotNull('e.reference_paiement')
            ->whereRaw("TRIM(e.reference_paiement) <> ''")
            ->groupByRaw('UPPER(TRIM(e.reference_paiement))')
            ->havingRaw('COUNT(*) > 1')
            ->selectRaw('UPPER(TRIM(e.reference_paiement)) as reference')
            ->pluck('reference');

        return $doublons->map(function (string $reference) use ($organizationId) {
            $encaissements = EncaissementVente::query()
                ->select('encaissements_ventes.*')
                ->join('factures_ventes as f', 'f.id', '=', 'encaissements_ventes.facture_vente_id')
                ->where('f.organization_id', $organizationId)
                ->where('encaissements_ventes.mode_paiement', ModePaiement::MOBILE_MONEY->value)
                ->whereRaw('UPPER(TRIM(encaissements_ventes.reference_paiement)) = ?', [$reference])
                ->with(['facture.commande:id,reference', 'creator', 'siteEncaissement:id,nom', 'compteTresorerie:id,libelle'])
                ->orderBy('encaissements_ventes.created_at')
                ->get();
            $premier = $encaissements->first();

            return [
                'cle' => 'reference_mobile_money:'.$reference,
                'type' => TypeAnomalieEncaissement::REFERENCE_MOBILE_MONEY_REUTILISEE,
                'organization_id' => $organizationId,
                'facture_vente_id' => null,
                'commande_vente_id' => null,
                'site_id' => $premier?->site_encaissement_id ?? $premier?->facture?->site_id,
                'montant_facture' => null,
                'montant_encaisse_net' => null,
                'surplus' => 0.0,
                'agent_ids' => $encaissements->pluck('created_by')->filter()->unique()->values()->all(),
                'details' => [
                    'explication' => 'La référence '.$reference.' figure sur '.$encaissements->count().' encaissements Mobile Money.',
                    'reference' => $reference,
                    'encaissements' => $encaissements->map(fn (EncaissementVente $e) => $this->detailEncaissement($e))->all(),
                    'suspects' => $encaissements->pluck('id')->all(),
                ],
            ];
        })->all();
    }

    // ── Construction ─────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, EncaissementVente>  $encaissements
     * @param  list<string>  $suspects
     * @return array<string, mixed>
     */
    private function constat(
        string $cle,
        TypeAnomalieEncaissement $type,
        ?FactureVente $facture,
        Collection $encaissements,
        ?float $encaisseNet,
        float $surplus,
        array $suspects,
        string $explication,
        bool $avecMontantsFacture = true,
    ): array {
        return [
            'cle' => $cle,
            'type' => $type,
            'organization_id' => $facture?->organization_id,
            'facture_vente_id' => $facture?->id,
            'commande_vente_id' => $facture?->commande_vente_id,
            'site_id' => $facture?->site_id ?? $encaissements->first()?->site_encaissement_id,
            'montant_facture' => $avecMontantsFacture && $facture ? round((float) $facture->montant_net, 2) : null,
            'montant_encaisse_net' => $encaisseNet,
            'surplus' => $surplus,
            'agent_ids' => $encaissements->whereIn('id', $suspects ?: $encaissements->pluck('id')->all())
                ->pluck('created_by')->filter()->unique()->values()->all(),
            'details' => [
                'explication' => $explication,
                'facture_reference' => $facture?->reference,
                'commande_reference' => $facture?->commande?->reference,
                'montant_rembourse' => $facture ? $facture->montantRembourse() : 0.0,
                'encaissements' => $encaissements->map(fn (EncaissementVente $e) => $this->detailEncaissement($e))->values()->all(),
                'suspects' => array_values($suspects),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function detailEncaissement(EncaissementVente $e): array
    {
        return [
            'id' => $e->id,
            'montant' => round((float) $e->montant, 2),
            'mode_paiement' => $e->mode_paiement?->value,
            'mode_label' => $e->mode_paiement?->label(),
            'operateur' => $e->operateur_mobile_money?->label(),
            'reference_paiement' => $e->reference_paiement,
            'date_encaissement' => $e->date_encaissement?->toDateString(),
            'cree_le' => $e->created_at?->toIso8601String(),
            'agent_id' => $e->created_by,
            'agent_nom' => $e->creator?->name,
            'site_encaissement' => $e->siteEncaissement?->nom,
            'support' => $e->compteTresorerie?->libelle,
            'est_acompte' => (bool) $e->est_acompte,
            'facture_reference' => $e->facture?->reference,
            'commande_reference' => $e->facture?->commande?->reference,
            'commande_id' => $e->facture?->commande_vente_id,
        ];
    }

    private static function ecartSecondes(EncaissementVente $a, EncaissementVente $b): int
    {
        return (int) abs($a->created_at->getTimestamp() - $b->created_at->getTimestamp());
    }

    public static function gnf(float $montant): string
    {
        return number_format($montant, 0, ',', ' ').' GNF';
    }
}
