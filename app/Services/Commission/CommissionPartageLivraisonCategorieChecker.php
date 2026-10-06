<?php

namespace App\Services\Commission;

use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Produit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Source unique de la règle "le partage Livreur par catégorie couvre-t-il cette opération ?" —
 * réutilisée par des appelants qui doivent absolument s'accorder sur la même réponse :
 * CommissionEnveloppeGenerator (génération réelle, différée au déclencheur configuré par
 * l'organisation), EquipeLivraisonController::validatePartagesCategorie (cohérence à la
 * sauvegarde de l'équipe) et les garde-fous préventifs à la création d'une opération
 * (CommandeVenteFormBuilder, TransfertLogistiqueController) — ces derniers réduisent le risque
 * qu'une commande/un transfert paraisse "payé(e)" mais reste bloqué(e) à "à régulariser" faute de
 * partage configuré (cf. incident CMD-300826-007, 30/08/2026), sans jamais remplacer le filet de
 * sécurité de la génération elle-même : la configuration peut encore changer entre la création
 * (contrôle ici) et la génération (différée).
 *
 * Barème Livreur résolu au niveau CATÉGORIE uniquement (jamais variante/produit) : le partage
 * entre livreurs n'a lui-même jamais été défini plus finement qu'une catégorie (cf.
 * EquipeLivraisonPartageCategorie).
 */
class CommissionPartageLivraisonCategorieChecker
{
    /**
     * Enveloppe PAR_UNITE_VENDUE à distribuer entre livreurs pour cette catégorie/processus — 0
     * si aucune règle active ne la couvre (décision AMOA #4 : absence de règle = 0, jamais une
     * erreur). $processusId peut être null (processus jamais configuré pour l'organisation) :
     * revient alors toujours à 0, aucune CommissionRegle ne pouvant référencer un processus
     * inexistant.
     */
    public static function resoudreEnveloppe(
        string $organizationId,
        ?string $processusId,
        string $categorieId,
        ?string $typeVehiculeId,
        CarbonInterface $date,
    ): int {
        if ($processusId === null) {
            return 0;
        }

        $regle = CommissionRegleResolver::resolve(
            $organizationId,
            $processusId,
            CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            null,
            null,
            $categorieId,
            $date,
            $typeVehiculeId,
        );

        return (int) round((float) ($regle?->montant ?? 0));
    }

    /**
     * Lignes de partage actives (effective_from/effective_to) pour cette équipe/catégorie/
     * processus à la date donnée — jamais la config courante si $date est dans le passé (relance
     * d'une génération historique, cf. EquipeLivraisonController::syncPartagesCategorie).
     *
     * @return Collection<int, EquipeLivraisonPartageCategorie>
     */
    public static function partagesActifs(
        string $processusId,
        string $equipeId,
        string $categorieId,
        CarbonInterface $date,
    ): Collection {
        return EquipeLivraisonPartageCategorie::where('processus_id', $processusId)
            ->where('equipe_id', $equipeId)
            ->where('categorie_id', $categorieId)
            ->actifA($date)
            ->get();
    }

    /**
     * Catégories, parmi $categorieIds, dont le partage Livreur de l'équipe n'est PAS conforme à
     * $date — décision du 24/09/2026 : exige désormais un partage CONFORME (somme exactement égale
     * au barème, chaque membre actif présent, 0 GNF accepté), plus seulement un partage existant.
     * Jugé exclusivement par CommissionPartageLivraisonValidator (même juge que la génération et
     * l'enregistrement de l'équipe) ; les champs de détail (total, écart, membres sans part) ne
     * servent qu'à un message actionnable. Une enveloppe à 0 (aucune règle, ou règle à 0) n'exige
     * jamais de partage : "rien à distribuer" est une valeur métier valide.
     *
     * @param  iterable<string>  $categorieIds
     * @return Collection<int, array{categorie_id: string, categorie_nom: string, bareme: int, total_configure: int|null, ecart: int, membres_manquants: list<string>, motif: string}>
     */
    public static function nonConformites(
        string $organizationId,
        EquipeLivraison $equipe,
        string $processusCode,
        ?string $typeVehiculeId,
        iterable $categorieIds,
        CarbonInterface $date,
    ): Collection {
        $categorieIds = collect($categorieIds)->filter()->unique()->values();
        if ($categorieIds->isEmpty()) {
            return collect();
        }

        $processus = CommissionProcessus::where('organization_id', $organizationId)
            ->where('code', $processusCode)
            ->first();

        // Processus jamais configuré pour l'organisation : aucune CommissionRegle ne peut y
        // référer, l'enveloppe est donc toujours 0 — rien à exiger.
        if (! $processus) {
            return collect();
        }

        $membresRequis = self::membresRequis($equipe);
        $categories = Categorie::whereIn('id', $categorieIds)->get()->keyBy('id');

        return $categorieIds
            ->map(function (string $categorieId) use ($organizationId, $equipe, $processus, $typeVehiculeId, $date, $membresRequis, $categories) {
                $bareme = self::resoudreEnveloppe($organizationId, $processus->id, $categorieId, $typeVehiculeId, $date);
                if ($bareme <= 0) {
                    return null;
                }

                $partages = self::partagesActifs($processus->id, $equipe->id, $categorieId, $date);

                try {
                    CommissionPartageLivraisonValidator::valider(
                        $partages->map(fn (EquipeLivraisonPartageCategorie $p) => (object) [
                            'beneficiaire_id' => $p->livreur_id,
                            'montant_unitaire' => $p->montant_unitaire,
                        ]),
                        $bareme,
                        $membresRequis->keys(),
                    );

                    return null;
                } catch (InvalidArgumentException $e) {
                    $total = $partages->isEmpty() ? null : (int) $partages->sum('montant_unitaire');
                    $presents = $partages->pluck('livreur_id')->all();

                    return [
                        'categorie_id' => $categorieId,
                        'categorie_nom' => $categories->get($categorieId)?->nom ?? $categorieId,
                        'bareme' => $bareme,
                        'total_configure' => $total,
                        'ecart' => $bareme - ($total ?? 0),
                        'membres_manquants' => $partages->isEmpty()
                            ? []
                            : $membresRequis->reject(fn (string $nom, string $id) => in_array($id, $presents, true))->values()->all(),
                        'motif' => $e->getMessage(),
                    ];
                }
            })
            ->filter()
            ->values();
    }

    /**
     * Date d'effet d'une nouvelle version de partage pour (équipe, processus, catégorie) — option A
     * (décision du 24/09/2026). Quand la version active remplacée n'était PAS conforme au barème
     * Livreur applicable (somme ≠ barème, typiquement après un changement de barème), la correction
     * prend effet à la date d'effet de ce barème (jamais avant le début de la version remplacée,
     * pour ne jamais créer de chevauchement) : une relance d'une commission partielle née entre ces
     * deux dates retrouve alors, à sa date d'origine, une configuration valide — déterministe et
     * auditable (ancienne version conservée et bornée, nouvelle version tracée). Aucune enveloppe
     * déjà générée n'est concernée (instantanés figés). Dans tous les autres cas (version conforme
     * remplacée par choix, première configuration, barème à 0, membre seulement ajouté sans effet
     * sur les montants) : effet immédiat, comme avant.
     *
     * @param  Collection<int, EquipeLivraisonPartageCategorie>  $versionActive
     */
    public static function dateEffetNouvelleVersion(
        string $organizationId,
        string $processusId,
        string $categorieId,
        ?string $typeVehiculeId,
        Collection $versionActive,
        CarbonInterface $maintenant,
    ): CarbonInterface {
        if ($versionActive->isEmpty()) {
            return $maintenant;
        }

        $regle = CommissionRegleResolver::resolve(
            $organizationId,
            $processusId,
            CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            null,
            null,
            $categorieId,
            $maintenant,
            $typeVehiculeId,
        );
        $bareme = (int) round((float) ($regle?->montant ?? 0));

        if (! $regle || $bareme <= 0) {
            return $maintenant;
        }

        try {
            CommissionPartageLivraisonValidator::valider(
                $versionActive->map(fn (EquipeLivraisonPartageCategorie $p) => (object) [
                    'beneficiaire_id' => $p->livreur_id,
                    'montant_unitaire' => $p->montant_unitaire,
                ]),
                $bareme,
            );

            return $maintenant;
        } catch (InvalidArgumentException) {
            $debutVersion = $versionActive->max(fn (EquipeLivraisonPartageCategorie $p) => $p->effective_from);
            $debutBareme = $regle->effective_from->copy()->startOfDay();

            return $debutVersion && $debutVersion->gt($debutBareme) ? $debutVersion : $debutBareme;
        }
    }

    /**
     * Membres dont une ligne de partage est exigée : membres de l'équipe dont le livreur est
     * actif — un livreur désactivé mais encore rattaché n'est jamais exigé.
     *
     * @return Collection<string, string> livreur_id => nom affiché
     */
    public static function membresRequis(EquipeLivraison $equipe): Collection
    {
        return $equipe->membres()
            ->with('livreur')
            ->get()
            ->filter(fn (EquipeLivreur $m) => $m->livreur && $m->livreur->is_active)
            ->mapWithKeys(fn (EquipeLivreur $m) => [$m->livreur_id => $m->livreur->nom_complet ?? $m->livreur_id]);
    }

    /**
     * Message actionnable unique pour une non-conformité (création/modification de commande,
     * chargement, création de transfert, diagnostic) — jamais recomposé ailleurs.
     *
     * @param  array{categorie_nom: string, bareme: int, total_configure: int|null, ecart: int, membres_manquants: list<string>}  $nc
     */
    public static function libelleNonConformite(array $nc): string
    {
        $bareme = number_format($nc['bareme'], 0, ',', ' ');

        if ($nc['total_configure'] === null) {
            return sprintf('%s : barème Livreur %s GNF/pack, aucun partage configuré.', $nc['categorie_nom'], $bareme);
        }

        $libelle = sprintf(
            '%s : barème Livreur %s GNF/pack, partage configuré %s GNF/pack',
            $nc['categorie_nom'],
            $bareme,
            number_format($nc['total_configure'], 0, ',', ' '),
        );

        if ($nc['ecart'] !== 0) {
            $libelle .= sprintf(', écart %s%s GNF', $nc['ecart'] > 0 ? '' : '−', number_format(abs($nc['ecart']), 0, ',', ' '));
        }

        if (! empty($nc['membres_manquants'])) {
            $libelle .= ', sans part : '.implode(', ', $nc['membres_manquants']);
        }

        return $libelle.'.';
    }

    /**
     * Résout les catégories distinctes concernées par des lignes brutes de requête
     * ([['produit_id' => ..., ...], ...]) — un produit sans catégorie n'est jamais concerné par
     * un contrôle qui, par définition, se déclenche par catégorie (même règle que
     * VehiculeCapaciteService::verifier).
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array<int, string>
     */
    public static function categorieIdsDepuisLignes(array $lignes, string $produitKey = 'produit_id'): array
    {
        $produitIds = collect($lignes)->pluck($produitKey)->filter()->unique()->values()->all();

        return Produit::whereIn('id', $produitIds)
            ->whereNotNull('categorie_id')
            ->pluck('categorie_id')
            ->unique()
            ->values()
            ->all();
    }
}
