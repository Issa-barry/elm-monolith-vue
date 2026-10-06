<?php

namespace App\Services\Commission;

use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionUniteCalcul;
use App\Models\Categorie;
use App\Models\CommissionBaremeBrouillon;
use App\Models\CommissionCibleType;
use App\Models\CommissionRegle;
use App\Models\Prestataire;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Historique des modifications de barème d'un processus (Paramètres → Commissions, COMM-021) —
 * DÉRIVÉ des versions de `commission_regles`, jamais stocké : chaque enregistrement crée une
 * nouvelle version (`remplace_regle_id`) et clôt l'ancienne, l'historique complet existe donc déjà.
 *
 * - Ajout : version sans prédécesseur (auteur = `created_by`, date = `created_at`).
 * - Modification : version qui en remplace une autre (ancien → nouveau montant).
 * - Retrait : version close sans successeur (auteur = `closed_by`, date = `updated_at`). Les
 *   retraits antérieurs au 03/10/2026 n'ont pas d'auteur enregistré.
 *
 * Les changements d'un même enregistrement (même auteur, à moins d'une minute d'intervalle) sont
 * regroupés ; un retrait sans auteur rejoint l'enregistrement voisin dont il fait partie.
 */
class CommissionBaremeHistoriqueService
{
    public const TYPE_AJOUT = 'ajout';

    public const TYPE_MODIFICATION = 'modification';

    public const TYPE_RETRAIT = 'retrait';

    private const ECART_MAX_SECONDES = 60;

    private const ORDRE_CIBLES = [
        CommissionCibleType::CODE_PROPRIETAIRE,
        CommissionCibleType::CODE_EQUIPE_LIVRAISON,
        CommissionCibleType::CODE_SITE,
        CommissionCibleType::CODE_CONSULTANT,
    ];

    /**
     * @return list<array{id: string, date: string, auteur: ?string, publication_brouillon: bool, changements: list<array<string, mixed>>}>
     */
    public static function pour(string $organizationId, string $processusId): array
    {
        $regles = CommissionRegle::where('organization_id', $organizationId)
            ->where('processus_id', $processusId)
            ->where('unite_calcul', CommissionUniteCalcul::PAR_UNITE_VENDUE->value)
            ->where('statut', '!=', CommissionRegleStatut::BROUILLON->value)
            ->with(['typeVehicule', 'consultant.personne', 'consultant.entrepriseTierce'])
            ->get();

        if ($regles->isEmpty()) {
            return [];
        }

        $parId = $regles->keyBy('id');
        $remplacees = $regles->pluck('remplace_regle_id')->filter()->flip();
        $categories = Categorie::whereIn('id', $regles->pluck('scope_id')->filter()->unique())->pluck('nom', 'id');
        $auteurs = User::whereIn('id', $regles->pluck('created_by')->merge($regles->pluck('closed_by'))->filter()->unique())
            ->with('personne')
            ->get()
            ->keyBy('id');

        $evenements = collect();

        foreach ($regles as $regle) {
            $precedente = $regle->remplace_regle_id ? $parId->get($regle->remplace_regle_id) : null;

            $evenements->push([
                'date' => $regle->created_at,
                'auteur_id' => $regle->created_by,
                'changement' => self::changement(
                    $precedente ? self::TYPE_MODIFICATION : self::TYPE_AJOUT,
                    $regle,
                    $precedente,
                    $regle,
                    $regle->effective_from,
                    $categories,
                ),
            ]);

            if ($regle->statut === CommissionRegleStatut::REMPLACEE && ! $remplacees->has($regle->id)) {
                $evenements->push([
                    'date' => $regle->updated_at,
                    'auteur_id' => $regle->closed_by,
                    'changement' => self::changement(
                        self::TYPE_RETRAIT,
                        $regle,
                        $regle,
                        null,
                        $regle->effective_to?->copy()->addDay(),
                        $categories,
                    ),
                ]);
            }
        }

        $publications = CommissionBaremeBrouillon::where('organization_id', $organizationId)
            ->where('processus_id', $processusId)
            ->where('statut', CommissionBaremeBrouillon::STATUT_PUBLIE)
            ->whereNotNull('publie_le')
            ->pluck('publie_le')
            ->map(fn ($publieLe) => Carbon::parse($publieLe));

        return self::regrouper($evenements, $auteurs, $publications);
    }

    /**
     * @param  Collection<string, string>  $categories
     * @return array<string, mixed>
     */
    private static function changement(
        string $type,
        CommissionRegle $regle,
        ?CommissionRegle $avant,
        ?CommissionRegle $apres,
        ?Carbon $enVigueurLe,
        Collection $categories,
    ): array {
        return [
            'type' => $type,
            'categorie' => $regle->scope_type->value === 'global'
                ? 'Toutes catégories'
                : ($categories->get($regle->scope_id) ?? 'Catégorie supprimée'),
            'type_vehicule' => $regle->typeVehicule?->nom,
            'cible_code' => $regle->cible_type,
            'cible' => self::libelleCible($regle->cible_type),
            'ancien_montant' => $avant ? (int) $avant->montant : null,
            'nouveau_montant' => $apres ? (int) $apres->montant : null,
            'ancien_consultant' => $avant ? self::nomConsultant($avant->consultant) : null,
            'nouveau_consultant' => $apres ? self::nomConsultant($apres->consultant) : null,
            'en_vigueur_le' => $enVigueurLe?->toDateString(),
        ];
    }

    /**
     * @param  Collection<string, User>  $auteurs
     * @param  Collection<int, Carbon>  $publications
     */
    private static function regrouper(Collection $evenements, Collection $auteurs, Collection $publications): array
    {
        $groupes = [];
        $courant = null;

        foreach ($evenements->sortBy(fn (array $e) => $e['date']?->getTimestamp() ?? 0)->values() as $evenement) {
            $date = $evenement['date'] ?? Carbon::createFromTimestamp(0);
            $auteurId = $evenement['auteur_id'];

            $rattachable = $courant !== null
                && $date->getTimestamp() - $courant['fin']->getTimestamp() <= self::ECART_MAX_SECONDES
                && ($auteurId === null || $courant['auteur_id'] === null || $courant['auteur_id'] === $auteurId);

            if (! $rattachable) {
                if ($courant !== null) {
                    $groupes[] = $courant;
                }
                $courant = ['debut' => $date, 'fin' => $date, 'auteur_id' => $auteurId, 'changements' => []];
            }

            $courant['fin'] = $date;
            $courant['auteur_id'] ??= $auteurId;
            $courant['changements'][] = $evenement['changement'];
        }

        $groupes[] = $courant;

        return collect($groupes)
            ->reverse()
            ->map(fn (array $g) => [
                'id' => $g['debut']->format('YmdHis').'-'.($g['auteur_id'] ?? 'inconnu'),
                'date' => $g['debut']->toIso8601String(),
                'auteur' => $g['auteur_id'] ? ($auteurs->get($g['auteur_id'])?->name ?: null) : null,
                'publication_brouillon' => $publications->contains(
                    fn (Carbon $publieLe) => abs($publieLe->getTimestamp() - $g['debut']->getTimestamp()) <= self::ECART_MAX_SECONDES
                ),
                'changements' => collect($g['changements'])
                    ->sortBy(fn (array $c) => sprintf(
                        '%s|%d|%s|%d',
                        $c['categorie'],
                        $c['type_vehicule'] === null ? 0 : 1,
                        $c['type_vehicule'] ?? '',
                        self::rangCible($c['cible_code']),
                    ))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private static function rangCible(string $code): int
    {
        $rang = array_search($code, self::ORDRE_CIBLES, true);

        return $rang === false ? 99 : $rang;
    }

    private static function libelleCible(string $code): string
    {
        return match ($code) {
            CommissionCibleType::CODE_PROPRIETAIRE => 'Propriétaire',
            CommissionCibleType::CODE_EQUIPE_LIVRAISON => 'Livreur',
            CommissionCibleType::CODE_SITE => 'Site',
            CommissionCibleType::CODE_CONSULTANT => 'Consultant',
            default => $code,
        };
    }

    private static function nomConsultant(?Prestataire $consultant): ?string
    {
        return $consultant ? ($consultant->nom_complet ?? $consultant->reference) : null;
    }
}
