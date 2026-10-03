<?php

namespace App\Services\Tresorerie;

use App\Enums\StatutMouvementFonds;
use App\Models\EncaissementVente;
use App\Models\MouvementFonds;
use App\Models\MouvementFondsEncaissement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Dette inter-agences (ADR 0012), dérivée des encaissements eux-mêmes — jamais d'une table de
 * dettes ni d'un montant saisi : un encaissement dont l'agence d'encaissement diffère de l'agence
 * de la commande EST une ligne de dette (agence qui a encaissé → agence de la commande), de son
 * montant exact. Un encaissement ne peut donc jamais compter deux fois, et sa suppression
 * (contrepassée) fait disparaître la dette.
 *
 * Statut d'une ligne, selon le règlement inter-agences actif qui la couvre :
 *  - à verser             : aucun règlement ;
 *  - réservé              : dans un règlement en brouillon (l'argent est encore chez le débiteur) ;
 *  - en cours de versement : règlement envoyé ou contesté (l'argent a quitté le débiteur) ;
 *  - versé                : règlement reçu par l'agence de la commande.
 *
 * « À verser » d'une agence = ses lignes à verser + réservées. « À recevoir » de l'agence de la
 * commande = tout ce qui n'est pas encore versé. Le compte de liaison (181) du grand livre porte
 * les mêmes montants par agence et contrepartie — cf. tests de cohérence.
 */
class DetteInterAgencesService
{
    public const A_VERSER = 'a_verser';

    public const RESERVE = 'reserve';

    public const EN_COURS = 'en_cours_versement';

    public const VERSE = 'verse';

    /** Statut de synthèse d'un couple d'agences, jamais d'un encaissement individuel. */
    public const PARTIELLEMENT_VERSE = 'partiellement_verse';

    public const LIBELLES = [
        self::A_VERSER => 'À verser',
        self::RESERVE => 'Réservé (règlement en préparation)',
        self::EN_COURS => 'En cours de versement',
        self::VERSE => 'Versé',
    ];

    /**
     * Encaissements reçus pour le compte d'une autre agence, avec leur statut de règlement.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lignes(string $organizationId, ?string $siteDebiteurId = null, ?string $siteCreancierId = null): Collection
    {
        $encaissements = $this->requete($organizationId)
            ->when($siteDebiteurId, fn (Builder $q) => $q->where('encaissements_ventes.site_encaissement_id', $siteDebiteurId))
            ->when($siteCreancierId, fn (Builder $q) => $q->where('fv.site_id', $siteCreancierId))
            ->with(['facture.commande.client', 'facture.site', 'siteEncaissement', 'creator'])
            ->orderBy('encaissements_ventes.date_encaissement')
            ->get();

        $actives = $this->reglementsActifs($encaissements->pluck('id')->all());

        return $encaissements->map(function (EncaissementVente $e) use ($actives) {
            $mouvement = $actives->get($e->id)?->mouvement;
            $statut = self::statutPour($mouvement);

            return [
                'encaissement_id' => $e->id,
                'montant' => round((float) $e->montant, 2),
                'date_encaissement' => $e->date_encaissement?->toDateString(),
                'mode_paiement' => $e->mode_paiement?->value,
                'mode_paiement_label' => $e->operateur_mobile_money?->label() ?? $e->mode_paiement?->label(),
                'reference_paiement' => $e->reference_paiement,
                'site_debiteur_id' => $e->site_encaissement_id,
                'site_debiteur_nom' => $e->siteEncaissement?->nom,
                'site_creancier_id' => $e->facture?->site_id,
                'site_creancier_nom' => $e->facture?->site?->nom,
                'facture_reference' => $e->facture?->reference,
                'commande_id' => $e->facture?->commande_vente_id,
                'client_nom' => $e->facture?->commande?->client?->nom_complet,
                'auteur' => $e->creator?->name,
                'statut' => $statut,
                'statut_label' => self::LIBELLES[$statut],
                'mouvement_id' => $mouvement?->id,
                'mouvement_reference' => $mouvement?->reference,
            ];
        })->values();
    }

    /**
     * Statut de reversement d'encaissements déjà chargés (fiche commande) — lecture groupée, une seule
     * requête. Seuls les encaissements reçus par une autre agence que celle de la commande en ont un.
     *
     * @param  iterable<EncaissementVente>  $encaissements  avec leur facture chargée
     * @return array<string, array{statut:string, statut_label:string, mouvement_reference:?string}>
     */
    public function reversements(iterable $encaissements): array
    {
        $pourAutreAgence = collect($encaissements)->filter(fn (EncaissementVente $e) => $e->estPourAutreAgence());
        if ($pourAutreAgence->isEmpty()) {
            return [];
        }

        $actives = $this->reglementsActifs($pourAutreAgence->pluck('id')->all());

        return $pourAutreAgence->mapWithKeys(function (EncaissementVente $e) use ($actives) {
            $mouvement = $actives->get($e->id)?->mouvement;
            $statut = self::statutPour($mouvement);

            return [$e->id => [
                'statut' => $statut,
                'statut_label' => self::LIBELLES[$statut],
                'mouvement_reference' => $mouvement?->reference,
            ]];
        })->all();
    }

    /**
     * Soldes par couple (agence débitrice → agence créancière). `$siteIds` restreint aux couples dont
     * l'une des deux agences fait partie de la liste (null = toute l'organisation).
     *
     * @param  list<string>|null  $siteIds
     * @return Collection<int, array{site_debiteur_id:string, site_debiteur_nom:?string, site_creancier_id:string, site_creancier_nom:?string, a_verser:float, en_cours_versement:float, verse:float, a_recevoir:float, nombre_a_verser:int, statut:string, statut_label:string}>
     */
    public function soldes(string $organizationId, ?array $siteIds = null): Collection
    {
        return $this->lignes($organizationId)
            ->when($siteIds !== null, fn (Collection $l) => $l->filter(
                fn (array $ligne) => in_array($ligne['site_debiteur_id'], $siteIds, true) || in_array($ligne['site_creancier_id'], $siteIds, true)
            ))
            ->groupBy(fn (array $ligne) => $ligne['site_debiteur_id'].'|'.$ligne['site_creancier_id'])
            ->map(function (Collection $lignes) {
                $somme = fn (array $statuts) => round((float) $lignes->whereIn('statut', $statuts)->sum('montant'), 2);
                $premiere = $lignes->first();
                $aVerser = $somme([self::A_VERSER, self::RESERVE]);
                $enCours = $somme([self::EN_COURS]);
                $verse = $somme([self::VERSE]);
                // Un envoi en attente de réception reste prioritaire, même après un versement partiel.
                [$statut, $statutLabel] = match (true) {
                    $enCours > 0 => [self::EN_COURS, 'En cours de versement'],
                    $aVerser > 0 && $verse > 0 => [self::PARTIELLEMENT_VERSE, 'Partiellement versé'],
                    $aVerser > 0 => [self::A_VERSER, 'À envoyer'],
                    default => [self::VERSE, 'Versé'],
                };

                return [
                    'site_debiteur_id' => $premiere['site_debiteur_id'],
                    'site_debiteur_nom' => $premiere['site_debiteur_nom'],
                    'site_creancier_id' => $premiere['site_creancier_id'],
                    'site_creancier_nom' => $premiere['site_creancier_nom'],
                    'a_verser' => $aVerser,
                    'en_cours_versement' => $enCours,
                    'verse' => $verse,
                    'a_recevoir' => $somme([self::A_VERSER, self::RESERVE, self::EN_COURS]),
                    'nombre_a_verser' => $lignes->whereIn('statut', [self::A_VERSER, self::RESERVE])->count(),
                    'statut' => $statut,
                    'statut_label' => $statutLabel,
                ];
            })
            ->values();
    }

    /** Total qu'une agence détient encore pour le compte d'autres agences (à verser + réservé). */
    public function aVerserParSite(string $organizationId, string $siteId): float
    {
        return round((float) $this->soldes($organizationId, [$siteId])
            ->where('site_debiteur_id', $siteId)
            ->sum('a_verser'), 2);
    }

    public static function statutPour(?MouvementFonds $mouvement): string
    {
        return match (true) {
            $mouvement === null => self::A_VERSER,
            $mouvement->statut === StatutMouvementFonds::BROUILLON => self::RESERVE,
            $mouvement->statut === StatutMouvementFonds::RECU => self::VERSE,
            default => self::EN_COURS,
        };
    }

    /**
     * Ligne de règlement active de chaque encaissement donné, avec son mouvement.
     *
     * @param  list<string>  $encaissementIds
     * @return Collection<string, MouvementFondsEncaissement>
     */
    private function reglementsActifs(array $encaissementIds): Collection
    {
        return MouvementFondsEncaissement::whereIn('encaissement_actif_id', $encaissementIds)
            ->with('mouvement')
            ->get()
            ->keyBy('encaissement_actif_id');
    }

    /** Encaissements dont l'agence d'encaissement diffère de l'agence de la commande. */
    public function requete(string $organizationId): Builder
    {
        return EncaissementVente::query()
            ->select('encaissements_ventes.*')
            ->join('factures_ventes as fv', 'fv.id', '=', 'encaissements_ventes.facture_vente_id')
            ->where('fv.organization_id', $organizationId)
            ->whereNull('fv.deleted_at')
            ->whereNotNull('fv.site_id')
            ->whereNotNull('encaissements_ventes.site_encaissement_id')
            ->whereColumn('encaissements_ventes.site_encaissement_id', '<>', 'fv.site_id');
    }
}
