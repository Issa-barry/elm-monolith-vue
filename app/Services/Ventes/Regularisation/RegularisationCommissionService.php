<?php

namespace App\Services\Ventes\Regularisation;

use App\Enums\CommissionUniteCalcul;
use App\Enums\OrigineCommissionPart;
use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEcartRegularisation;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppeLigne;
use App\Models\CommissionEnveloppePart;
use App\Models\PaiementFiche;
use App\Models\PaiementFicheLigne;
use App\Models\PaiementPeriode;
use App\Models\RegularisationVente;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\PeriodeCalculatorService;
use Illuminate\Support\Carbon;

/**
 * Recalcul immédiat de la commission d'une vente régularisée après encaissement (ADR 0043).
 *
 * Le moteur officiel (CommissionEnveloppeGenerator::simulerPourCommandeVente()) calcule ce que la
 * commission AURAIT été sur les quantités corrigées, à la date de gain d'origine (mêmes barèmes,
 * même partage d'équipe). Puis, enveloppe par enveloppe :
 *  - une part NON engagée (rien versé, pas sur une fiche payée, période non clôturée) est remplacée
 *    par la part recalculée : la période concernée est rouverte et ses fiches recalculées par le
 *    mécanisme commun (lignes obsolètes / commissions hors fiches) ;
 *  - une part ENGAGÉE garde son identité et ses paiements (jamais touchés) ; son montant est
 *    recalculé en place et l'écart avec ce qui a été versé est enregistré
 *    (CommissionEcartRegularisation : trop-perçu ou complément), pour être régularisé ensuite par
 *    une opération financière distincte. Jamais de commission négative, jamais de compensation
 *    entre bénéficiaires.
 * Un montant ajusté à la main sur une part engagée suit la même proportion que le montant
 * théorique ; sur une part non engagée, l'ajustement et la validation sont à refaire (tracés dans
 * la régularisation).
 */
class RegularisationCommissionService
{
    /**
     * Plan du recalcul, sans rien écrire. Les lignes de la commande portent déjà les quantités
     * corrigées (en mémoire pour un aperçu, en base à l'exécution).
     *
     * @return array{enveloppes: list<array<string, mixed>>, parts: list<array<string, mixed>>, total_avant: float, total_apres: float, blocages: list<string>}
     */
    public function planifier(CommandeVente $commande): array
    {
        $enveloppes = CommissionEnveloppe::query()
            ->where('source_type', CommandeVente::class)
            ->where('source_id', $commande->id)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->with('parts')
            ->orderBy('created_at')
            ->get();

        $plan = ['enveloppes' => [], 'parts' => [], 'total_avant' => 0.0, 'total_apres' => 0.0, 'blocages' => []];
        if ($enveloppes->isEmpty()) {
            return $plan;
        }

        $earnedAt = Carbon::parse($enveloppes->min('earned_at'));
        $simulation = CommissionEnveloppeGenerator::simulerPourCommandeVente($commande, $earnedAt);

        foreach ($enveloppes as $enveloppe) {
            $cle = self::cle($enveloppe);
            $recalculee = $simulation['enveloppes'][$cle] ?? null;

            if ($recalculee === null) {
                $erreur = collect($simulation['erreurs'])->first(fn (array $e) => ($e['cle'] ?? $e['cible']) === $cle || $e['cible'] === $enveloppe->cible_type);
                $plan['blocages'][] = sprintf(
                    'La commission « %s » ne peut pas être recalculée sur les quantités corrigées%s',
                    self::libelleCible($enveloppe->cible_type),
                    $erreur ? ' : '.$erreur['message'] : '.',
                );

                continue;
            }

            $partsRecalculees = collect($recalculee['parts'])->keyBy(fn (array $p) => $p['beneficiaire_type'].':'.$p['beneficiaire_id']);
            $parts = $enveloppe->parts->filter(fn (CommissionEnveloppePart $p) => $p->statut !== StatutCommission::ANNULEE);
            $engagees = [];

            foreach ($parts as $part) {
                $cleBeneficiaire = $part->beneficiaire_type.':'.$part->beneficiaire_id;
                $engagee = $this->estEngagee($part, $enveloppe);
                $theoriqueAvant = (float) $part->montant_net;
                $avant = $part->montant_a_payer;

                if ($engagee) {
                    $engagees[] = $cleBeneficiaire;
                    // Bénéficiaire absent du recalcul (équipe modifiée depuis) : même proportion que
                    // l'enveloppe, plutôt qu'un montant inventé.
                    $theoriqueApres = $partsRecalculees->has($cleBeneficiaire)
                        ? (float) $partsRecalculees[$cleBeneficiaire]['montant']
                        : ((float) $enveloppe->montant_total > 0 ? round($theoriqueAvant * (float) $recalculee['montant'] / (float) $enveloppe->montant_total, 2) : 0.0);
                    $apres = $part->montant_actuel !== null && $theoriqueAvant > 0
                        ? round((float) $part->montant_actuel * $theoriqueApres / $theoriqueAvant, 2)
                        : $theoriqueApres;
                } else {
                    $theoriqueApres = (float) ($partsRecalculees[$cleBeneficiaire]['montant'] ?? 0);
                    $apres = $theoriqueApres;
                }

                $verse = (float) $part->montant_verse;
                $ecart = $engagee ? round($verse - $apres, 2) : 0.0;

                $plan['parts'][] = [
                    'part_id' => $part->id,
                    'enveloppe_id' => $enveloppe->id,
                    'cible' => self::libelleCible($enveloppe->cible_type),
                    'beneficiaire_type' => $part->beneficiaire_type,
                    'beneficiaire_id' => $part->beneficiaire_id,
                    'beneficiaire' => self::libelleBeneficiaire($part),
                    'engagee' => $engagee,
                    'statut' => $part->statut?->value,
                    'ajustee' => $part->montant_actuel !== null,
                    'validee' => $part->validated_at !== null,
                    'theorique_apres' => round($theoriqueApres, 2),
                    'montant_avant' => round($avant, 2),
                    'montant_apres' => round($apres, 2),
                    'montant_verse' => round($verse, 2),
                    'ecart' => $ecart,
                    'sens' => self::sens($ecart),
                ];

                $plan['total_avant'] += $avant;
                $plan['total_apres'] += $apres;
            }

            // Bénéficiaires du recalcul sans part existante (non engagée) : nouvelles parts.
            foreach ($partsRecalculees as $cleBeneficiaire => $p) {
                if (in_array($cleBeneficiaire, $engagees, true)
                    || $parts->contains(fn (CommissionEnveloppePart $x) => $x->beneficiaire_type.':'.$x->beneficiaire_id === $cleBeneficiaire)) {
                    continue;
                }

                $plan['parts'][] = [
                    'part_id' => null,
                    'enveloppe_id' => $enveloppe->id,
                    'cible' => self::libelleCible($enveloppe->cible_type),
                    'beneficiaire_type' => $p['beneficiaire_type'],
                    'beneficiaire_id' => $p['beneficiaire_id'],
                    'beneficiaire' => self::libelleBeneficiaire(new CommissionEnveloppePart(['beneficiaire_type' => $p['beneficiaire_type'], 'beneficiaire_id' => $p['beneficiaire_id']])),
                    'engagee' => false,
                    'statut' => null,
                    'ajustee' => false,
                    'validee' => false,
                    'theorique_apres' => round((float) $p['montant'], 2),
                    'montant_avant' => 0.0,
                    'montant_apres' => round((float) $p['montant'], 2),
                    'montant_verse' => 0.0,
                    'ecart' => 0.0,
                    'sens' => CommissionEcartRegularisation::SENS_AUCUN,
                ];
                $plan['total_apres'] += (float) $p['montant'];
            }

            $plan['enveloppes'][] = [
                'id' => $enveloppe->id,
                'cle' => $cle,
                'cible' => self::libelleCible($enveloppe->cible_type),
                'montant_avant' => (float) $enveloppe->montant_total,
                'montant_apres' => round((float) $recalculee['montant'], 2),
                'recalcul' => $recalculee,
            ];
        }

        $plan['total_avant'] = round($plan['total_avant'], 2);
        $plan['total_apres'] = round($plan['total_apres'], 2);

        return $plan;
    }

    /**
     * Applique le plan (dans la transaction de la régularisation) et enregistre les écarts des parts
     * engagées. Retourne la liste des écarts créés.
     *
     * @param  array<string, mixed>  $plan  résultat de planifier(), calculé sur les quantités en base
     * @return list<CommissionEcartRegularisation>
     */
    public function appliquer(CommandeVente $commande, RegularisationVente $regularisation, array $plan): array
    {
        $ecarts = [];
        $dates = [];

        foreach ($plan['enveloppes'] as $env) {
            /** @var CommissionEnveloppe $enveloppe */
            $enveloppe = CommissionEnveloppe::whereKey($env['id'])->lockForUpdate()->firstOrFail();
            $recalculee = $env['recalcul'];
            $dates[] = $enveloppe->earned_at;

            $enveloppe->lignes()->delete();
            foreach ($recalculee['contributions'] as $c) {
                CommissionEnveloppeLigne::create([
                    'enveloppe_id' => $enveloppe->id,
                    'source_ligne_type' => $c['ligne']::class,
                    'source_ligne_id' => $c['ligne']->id,
                    'variante_id' => $c['variante']?->id,
                    'categorie_id_snapshot' => $c['categorie']?->id,
                    'commission_regle_id' => $c['regle']->id,
                    'quantite' => $c['quantite'],
                    'unite_calcul_snapshot' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
                    'montant_ligne' => $c['montant'],
                ]);
            }
            $enveloppe->update(['montant_total' => $recalculee['montant']]);

            $partsPlan = collect($plan['parts'])->where('enveloppe_id', $enveloppe->id);
            $recalculeesParBeneficiaire = collect($recalculee['parts'])->keyBy(fn (array $p) => $p['beneficiaire_type'].':'.$p['beneficiaire_id']);

            foreach ($partsPlan as $p) {
                if ($p['part_id'] === null) {
                    $this->creerPart($enveloppe, $recalculeesParBeneficiaire[$p['beneficiaire_type'].':'.$p['beneficiaire_id']]);

                    continue;
                }

                $part = CommissionEnveloppePart::whereKey($p['part_id'])->lockForUpdate()->firstOrFail();

                if (! $p['engagee']) {
                    // Remplacée par la part recalculée : la ligne de fiche qui la portait devient
                    // obsolète et la période est rouverte puis recalculée (mécanisme commun).
                    $part->delete();
                    $cle = $p['beneficiaire_type'].':'.$p['beneficiaire_id'];
                    if ($recalculeesParBeneficiaire->has($cle) && (float) $recalculeesParBeneficiaire[$cle]['montant'] > 0) {
                        $this->creerPart($enveloppe, $recalculeesParBeneficiaire[$cle]);
                    }

                    continue;
                }

                $part->forceFill([
                    'montant_brut' => $p['theorique_apres'],
                    'montant_net' => $p['theorique_apres'],
                    'montant_actuel' => $p['ajustee'] ? $p['montant_apres'] : null,
                ])->saveQuietly();

                if ((float) $part->montant_verse > 0) {
                    $part->recalculStatut();
                }

                $ecarts[] = CommissionEcartRegularisation::create([
                    'organization_id' => $commande->organization_id,
                    'regularisation_vente_id' => $regularisation->id,
                    'commande_vente_id' => $commande->id,
                    'commission_enveloppe_part_id' => $part->id,
                    'beneficiaire_type' => $part->beneficiaire_type,
                    'beneficiaire_id' => $part->beneficiaire_id,
                    'beneficiaire_libelle' => $p['beneficiaire'],
                    'montant_avant' => $p['montant_avant'],
                    'montant_apres' => $p['montant_apres'],
                    'montant_verse' => $p['montant_verse'],
                    'ecart' => $p['ecart'],
                    'sens' => $p['sens'],
                    'statut' => $p['sens'] !== CommissionEcartRegularisation::SENS_AUCUN
                        ? CommissionEcartRegularisation::STATUT_A_REGULARISER
                        : CommissionEcartRegularisation::STATUT_SANS_OBJET,
                ]);
            }

            $enveloppe->refresh()->recalculStatutGlobal();
        }

        // Fiches qui portent une part recalculée : leur reste à payer est ramené au montant
        // recalculé (PaiementFiche::recalculReductionRegularisation), sans toucher à leurs montants
        // figés ni à leurs paiements.
        $partIds = collect($ecarts)->pluck('commission_enveloppe_part_id');
        if ($partIds->isNotEmpty()) {
            PaiementFiche::whereIn('id', PaiementFicheLigne::where('source_type', CommissionEnveloppePart::class)
                ->whereIn('source_id', $partIds)
                ->select('fiche_id'))
                ->lockForUpdate()
                ->get()
                ->each(fn (PaiementFiche $fiche) => $fiche->recalculStatut());
        }

        foreach (collect($dates)->unique() as $date) {
            app(PeriodeCalculatorService::class)->recalculerPeriodesConcernees($commande->organization_id, Carbon::parse($date));
        }

        return $ecarts;
    }

    /**
     * Un complément (versé < recalculé) se paie par la fiche, comme tout reste dû : il est soldé
     * automatiquement dès que la part a reçu son montant recalculé, et rouvert si ce paiement est
     * annulé. Appelé après chaque allocation / désallocation d'un paiement de fiche.
     *
     * @param  iterable<string>  $partIds
     */
    public static function synchroniserComplements(iterable $partIds): void
    {
        $ecarts = CommissionEcartRegularisation::query()
            ->whereIn('commission_enveloppe_part_id', collect($partIds)->all())
            ->where('sens', CommissionEcartRegularisation::SENS_COMPLEMENT)
            ->whereNull('regularise_par')
            ->with('part')
            ->get();

        foreach ($ecarts as $ecart) {
            $part = $ecart->part;
            if ($part === null) {
                continue;
            }

            $solde = (float) $part->montant_verse >= $part->montant_a_payer - 0.004;
            if ($solde && $ecart->estARegulariser()) {
                $ecart->update([
                    'statut' => CommissionEcartRegularisation::STATUT_REGULARISE,
                    'commentaire_regularisation' => CommissionEcartRegularisation::COMMENTAIRE_COMPLEMENT_VERSE,
                    'regularise_at' => now(),
                ]);
            } elseif (! $solde && $ecart->statut === CommissionEcartRegularisation::STATUT_REGULARISE) {
                $ecart->update([
                    'statut' => CommissionEcartRegularisation::STATUT_A_REGULARISER,
                    'commentaire_regularisation' => null,
                    'regularise_at' => null,
                ]);
            }
        }
    }

    /** @param  array<string, mixed>  $p */
    private function creerPart(CommissionEnveloppe $enveloppe, array $p): void
    {
        CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => $p['beneficiaire_type'],
            'beneficiaire_id' => $p['beneficiaire_id'],
            'taux_repartition_snapshot' => $p['taux'] ?? null,
            'montant_unitaire_snapshot' => $p['montant_unitaire'] ?? null,
            'montant_brut' => $p['montant'],
            'montant_net' => $p['montant'],
            'statut' => StatutCommission::CREEE->value,
            'origine' => OrigineCommissionPart::THEORIQUE->value,
            'validated_at' => in_array($p['beneficiaire_type'], CommissionEnveloppeGenerator::TYPES_VALIDATION_AUTOMATIQUE, true) ? now() : null,
        ]);
    }

    /**
     * Engagée envers son bénéficiaire : quelque chose a été versé, ou elle figure sur une fiche figée
     * (payée, même en partie), ou sa période est clôturée — elle ne peut plus être remplacée.
     */
    private function estEngagee(CommissionEnveloppePart $part, CommissionEnveloppe $enveloppe): bool
    {
        if ((float) $part->montant_verse > 0 || $part->statut === StatutCommission::PAYE) {
            return true;
        }

        $fiches = PaiementFiche::whereIn('id', PaiementFicheLigne::where('source_type', CommissionEnveloppePart::class)
            ->where('source_id', $part->id)
            ->select('fiche_id'))->get();
        if ($fiches->contains(fn (PaiementFiche $f) => $f->estFigee())) {
            return true;
        }

        $date = Carbon::parse($enveloppe->earned_at)->toDateString();

        return PaiementPeriode::where('organization_id', $enveloppe->organization_id)
            ->whereDate('date_debut', '<=', $date)
            ->whereDate('date_fin', '>=', $date)
            ->get()
            ->contains(fn (PaiementPeriode $p) => $p->isCloturee());
    }

    private static function sens(float $ecart): string
    {
        return match (true) {
            $ecart > 0.004 => CommissionEcartRegularisation::SENS_TROP_PERCU,
            $ecart < -0.004 => CommissionEcartRegularisation::SENS_COMPLEMENT,
            default => CommissionEcartRegularisation::SENS_AUCUN,
        };
    }

    private static function cle(CommissionEnveloppe $enveloppe): string
    {
        return $enveloppe->cible_type === CommissionCibleType::CODE_CONSULTANT
            ? "{$enveloppe->cible_type}:{$enveloppe->cible_id}"
            : $enveloppe->cible_type;
    }

    private static function libelleCible(string $cible): string
    {
        return match ($cible) {
            CommissionCibleType::CODE_PROPRIETAIRE => 'Propriétaire',
            CommissionCibleType::CODE_EQUIPE_LIVRAISON => 'Livreurs',
            CommissionCibleType::CODE_SITE => 'Site',
            CommissionCibleType::CODE_CONSULTANT => 'Consultant',
            default => $cible,
        };
    }

    private static function libelleBeneficiaire(CommissionEnveloppePart $part): string
    {
        $beneficiaire = $part->resoudreBeneficiaire();
        if (! $beneficiaire) {
            return '—';
        }

        if (method_exists($beneficiaire, 'libelleAffichage')) {
            return (string) $beneficiaire->libelleAffichage();
        }

        foreach (['nom_affichage', 'nom_complet', 'nom'] as $attribut) {
            $valeur = $beneficiaire->getAttribute($attribut);
            if (is_string($valeur) && trim($valeur) !== '') {
                return trim($valeur);
            }
        }

        return '—';
    }
}
