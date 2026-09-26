<?php

namespace App\Services\Tresorerie;

use App\Enums\EvenementComptable;
use App\Enums\NatureMouvementFonds;
use App\Enums\StatutMouvementFonds;
use App\Models\CompteTresorerie;
use App\Models\EcritureComptable;
use App\Models\EncaissementVente;
use App\Models\MouvementFonds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Fiche d'une caisse dédiée sur une période : tableau de caisse (solde de début + mouvements =
 * solde de fin) et suivi des versements. Source unique de l'onglet « Caisse » du rapport
 * d'activité / de « Ma situation », prévue pour la fiche caisse (phase 4 du chantier caisses
 * dédiées) : un seul calcul, jamais deux affichages de caisse qui pourraient diverger.
 *
 * Tout vient du grand livre, comme TresorerieDisponibiliteService::soldePourSupport() (même compte,
 * même site, même organisation, date de pièce) : jamais un « encaissements − versements de la
 * période », qui ignorerait le solde reporté et ce qui a été versé d'une période sur l'autre. Les
 * mouvements sont regroupés selon l'événement réel de leur pièce — aucune catégorie inventée.
 *
 * Constat, pas contrôle : aucun comptage physique n'existe encore (écart de caisse = lot 3).
 */
class FicheCaisseService
{
    public const CATEGORIES = [
        'encaissements' => 'Encaissements espèces',
        'encaissements_annules' => 'Encaissements annulés (contrepassés)',
        'versements_envoyes' => 'Versements envoyés',
        'versements_renvoyes' => "Versements renvoyés à l'agent",
        'contrepassations' => 'Autres contrepassations',
    ];

    public function __construct(private readonly TresorerieDisponibiliteService $disponibilite) {}

    /**
     * @return array<string, mixed>
     */
    public function pour(CompteTresorerie $caisse, CarbonImmutable $debut, CarbonImmutable $fin, bool $avecEcritures = true): array
    {
        $caisse->loadMissing(['agent.personne', 'site']);

        $soldeDebut = $this->disponibilite->soldePourSupport($caisse, $debut->subDay()->toMutable());
        $soldeFin = $this->disponibilite->soldePourSupport($caisse, $fin->toMutable());

        $ecritures = $this->ecritures($caisse, $debut, $fin);

        $mouvements = $ecritures
            ->groupBy('categorie')
            ->map(fn (Collection $lignes, string $categorie) => [
                'categorie' => $categorie,
                'libelle' => $lignes->first()['categorie_libelle'],
                'entrees' => round((float) $lignes->sum('entree'), 2),
                'sorties' => round((float) $lignes->sum('sortie'), 2),
                'nombre' => $lignes->count(),
            ])
            ->sortBy(fn (array $m) => array_search($m['categorie'], array_keys(self::CATEGORIES), true) === false
                ? PHP_INT_MAX
                : array_search($m['categorie'], array_keys(self::CATEGORIES), true))
            ->values();

        $versements = $this->versements($caisse);
        $dernier = $versements->first();

        return [
            'caisse' => [
                'id' => $caisse->id,
                'libelle' => $caisse->libelle,
                'actif' => (bool) $caisse->actif,
                'site_id' => $caisse->site_id,
                'site_nom' => $caisse->site?->nom,
                'agent_id' => $caisse->agent_id,
                'agent_nom' => $caisse->agent?->name,
            ],
            'solde_debut' => $soldeDebut,
            'solde_fin' => $soldeFin,
            'total_entrees' => round((float) $ecritures->sum('entree'), 2),
            'total_sorties' => round((float) $ecritures->sum('sortie'), 2),
            'mouvements' => $mouvements->all(),
            'solde_actuel' => $this->disponibilite->soldePourSupport($caisse, now()),
            'en_cours' => $this->resumeVersements($versements, StatutMouvementFonds::ENVOYE),
            'contestes' => $this->resumeVersements($versements, StatutMouvementFonds::CONTESTE),
            'versements_periode' => $versements
                ->filter(fn (array $v) => $v['date_envoi'] !== null
                    && $v['date_envoi'] >= $debut->toDateString()
                    && $v['date_envoi'] <= $fin->toDateString())
                ->values()
                ->all(),
            'dernier_versement' => $dernier === null ? null : [
                ...$dernier,
                'anciennete_jours' => (int) CarbonImmutable::parse($dernier['date_envoi'])->startOfDay()
                    ->diffInDays(CarbonImmutable::now()->startOfDay()),
            ],
            'ecritures' => $avecEcritures ? $this->avecSoldeCumule($ecritures, $soldeDebut) : null,
        ];
    }

    /**
     * Écritures du compte propre de la caisse sur la période, dans l'ordre du grand livre.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function ecritures(CompteTresorerie $caisse, CarbonImmutable $debut, CarbonImmutable $fin): Collection
    {
        $mouvementMorph = (new MouvementFonds)->getMorphClass();
        $encaissementMorph = (new EncaissementVente)->getMorphClass();

        return EcritureComptable::query()
            ->join('compta_pieces as p', 'p.id', '=', 'compta_ecritures.piece_comptable_id')
            ->where('compta_ecritures.compte_comptable_id', $caisse->compte_comptable_id)
            ->where('compta_ecritures.site_id', $caisse->site_id)
            ->where('p.organization_id', $caisse->organization_id)
            ->whereDate('p.date_piece', '>=', $debut->toDateString())
            ->whereDate('p.date_piece', '<=', $fin->toDateString())
            ->orderBy('p.date_piece')
            ->orderBy('p.created_at')
            ->orderBy('compta_ecritures.id')
            ->get([
                'compta_ecritures.debit', 'compta_ecritures.credit', 'compta_ecritures.libelle as ligne_libelle',
                'p.id as piece_id', 'p.numero', 'p.date_piece', 'p.libelle as piece_libelle',
                'p.type_evenement', 'p.source_type',
            ])
            ->map(function ($ligne) use ($mouvementMorph, $encaissementMorph) {
                $categorie = $this->categorie((string) $ligne->type_evenement, (string) $ligne->source_type, $mouvementMorph, $encaissementMorph);

                return [
                    'piece_id' => $ligne->piece_id,
                    'numero' => $ligne->numero,
                    'date' => CarbonImmutable::parse($ligne->date_piece)->toDateString(),
                    'libelle' => $ligne->piece_libelle ?: $ligne->ligne_libelle,
                    'categorie' => $categorie,
                    'categorie_libelle' => self::CATEGORIES[$categorie]
                        ?? EvenementComptable::tryFrom((string) $ligne->type_evenement)?->label()
                        ?? (string) $ligne->type_evenement,
                    'entree' => round((float) $ligne->debit, 2),
                    'sortie' => round((float) $ligne->credit, 2),
                ];
            });
    }

    private function categorie(string $evenement, string $sourceType, string $mouvementMorph, string $encaissementMorph): string
    {
        if (str_starts_with($evenement, 'contrepassation_de_')) {
            return match ($sourceType) {
                $mouvementMorph => 'versements_renvoyes',
                $encaissementMorph => 'encaissements_annules',
                default => 'contrepassations',
            };
        }

        return match ($evenement) {
            EvenementComptable::ENCAISSEMENT_VENTE_RECU->value => 'encaissements',
            EvenementComptable::MOUVEMENT_FONDS_ENVOYE->value => 'versements_envoyes',
            default => $evenement,
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $ecritures
     * @return list<array<string, mixed>>
     */
    private function avecSoldeCumule(Collection $ecritures, float $soldeDebut): array
    {
        $solde = $soldeDebut;

        return $ecritures->map(function (array $ligne) use (&$solde) {
            $solde = round($solde + $ligne['entree'] - $ligne['sortie'], 2);

            return [...$ligne, 'solde' => $solde];
        })->all();
    }

    /**
     * Versements de la caisse vers l'agence (hors brouillons et annulés), du plus récent au plus ancien.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function versements(CompteTresorerie $caisse): Collection
    {
        return MouvementFonds::query()
            ->where('organization_id', $caisse->organization_id)
            ->where('nature', NatureMouvementFonds::INTERNE_CAISSES->value)
            ->where('compte_tresorerie_origine_id', $caisse->id)
            ->whereNotIn('statut', [StatutMouvementFonds::BROUILLON->value, StatutMouvementFonds::ANNULE->value])
            ->whereNotNull('date_envoi')
            ->orderByDesc('date_envoi')
            ->orderByDesc('created_at')
            ->get(['id', 'reference', 'montant', 'statut', 'date_envoi', 'date_reception'])
            ->map(fn (MouvementFonds $m) => [
                'id' => $m->id,
                'reference' => $m->reference,
                'montant' => round((float) $m->montant, 2),
                'statut' => $m->statut->value,
                'statut_label' => $m->statut->label(),
                'date_envoi' => CarbonImmutable::parse($m->date_envoi)->toDateString(),
                'date_reception' => $m->date_reception ? CarbonImmutable::parse($m->date_reception)->toDateString() : null,
            ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $versements
     * @return array{nombre: int, montant: float}
     */
    private function resumeVersements(Collection $versements, StatutMouvementFonds $statut): array
    {
        $lignes = $versements->where('statut', $statut->value);

        return ['nombre' => $lignes->count(), 'montant' => round((float) $lignes->sum('montant'), 2)];
    }
}
