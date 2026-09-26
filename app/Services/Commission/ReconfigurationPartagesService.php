<?php

namespace App\Services\Commission;

use App\Http\Controllers\Settings\CommissionRegleController;
use App\Models\Categorie;
use App\Models\CommissionBaremeBrouillon;
use App\Models\CommissionBaremeBrouillonPartage;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Changement de barème Livreur sur plusieurs centaines d'équipes (lot 2, ADR 0006) :
 * brouillon → reconfiguration groupée des partages → publication atomique.
 *
 * Une (équipe, catégorie) est CONCERNÉE par un brouillon quand le barème Livreur que le brouillon
 * appliquerait au type de son véhicule est positif, diffère du barème en vigueur, et que le
 * partage réel de l'équipe ne lui serait pas conforme (somme + membres actifs, même juge que la
 * commande : CommissionPartageLivraisonValidator). Une catégorie inchangée n'est jamais
 * concernée, même si son partage était déjà non conforme (ce cas relève du blocage à la commande
 * et du diagnostic `commissions:diagnostiquer-partages`, pas de ce brouillon).
 *
 * Seules les équipes à PLUSIEURS livreurs actifs sont à reconfigurer à la main (décision du
 * 25/09/2026) : une équipe à un seul livreur actif n'a aucune répartition à décider, sa part est
 * alignée automatiquement sur le nouveau barème, à la même date d'effet, au moment où le barème
 * est appliqué (directement ou à la publication). Une équipe sans livreur actif est ignorée.
 *
 * Le brouillon ne change RIEN à l'opérationnel : barème et partages en vigueur restent ceux
 * utilisés par les commandes et la génération jusqu'à la publication, qui écrit barème + partages
 * dans une seule transaction, à la même date d'effet (aujourd'hui).
 */
class ReconfigurationPartagesService
{
    public const STATUT_A_CORRIGER = 'a_corriger';

    public const STATUT_CONFORME = 'conforme';

    public const STATUT_A_REVALIDER = 'a_revalider';

    /**
     * Enregistre une configuration saisie dans Paramètres → Commissions : appliquée
     * immédiatement si aucune équipe n'est concernée (comportement historique), sinon versée
     * dans le brouillon en cours du processus (créé au besoin) — jamais publiée tant que les
     * partages concernés ne sont pas tous conformes.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array{brouillon: ?CommissionBaremeBrouillon, nb_groupes: int}
     */
    public static function enregistrerConfiguration(string $orgId, string $processusCode, array $lignes, ?string $userId): array
    {
        return DB::transaction(function () use ($orgId, $processusCode, $lignes, $userId) {
            $processus = CommissionProcessusDefaults::resoudreOuCreer($orgId, $processusCode);
            $brouillon = CommissionBaremeBrouillon::where('organization_id', $orgId)
                ->where('processus_id', $processus->id)
                ->where('statut', CommissionBaremeBrouillon::STATUT_EN_COURS)
                ->lockForUpdate()
                ->first();

            ['groupes' => $groupes, 'automatiques' => $automatiques] = self::analyser($orgId, $processus, $lignes, $brouillon);

            if ($groupes->isEmpty()) {
                CommissionBaremeConfigurationService::appliquer($orgId, $processusCode, $lignes, $userId);
                self::appliquerAutomatiques($processus->id, $automatiques);
                $brouillon?->update([
                    'lignes' => $lignes,
                    'statut' => CommissionBaremeBrouillon::STATUT_PUBLIE,
                    'publie_par' => $userId,
                    'publie_le' => now(),
                    'updated_by' => $userId,
                ]);

                return ['brouillon' => null, 'nb_groupes' => 0];
            }

            $attributs = [
                'lignes' => $lignes,
                'regles_signature' => CommissionBaremeConfigurationService::signatureReglesActives($orgId, $processus->id),
                'updated_by' => $userId,
            ];

            if ($brouillon) {
                $brouillon->update($attributs);
            } else {
                $brouillon = CommissionBaremeBrouillon::create([
                    ...$attributs,
                    'organization_id' => $orgId,
                    'processus_id' => $processus->id,
                    'statut' => CommissionBaremeBrouillon::STATUT_EN_COURS,
                    'created_by' => $userId,
                ]);
            }

            return ['brouillon' => $brouillon, 'nb_groupes' => $groupes->count()];
        });
    }

    /**
     * Aperçu, avant confirmation, du nombre d'équipes que $lignes rendrait à reconfigurer — même
     * calcul que enregistrerConfiguration(), sans aucune écriture.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array{nb_groupes: int, nb_equipes: int, nb_automatiques: int, nb_sans_livreur: int, nb_vehicules_inactifs: int, par_categorie: list<array{categorie: string, nb: int}>}
     */
    public static function apercu(string $orgId, string $processusCode, array $lignes): array
    {
        $processus = CommissionProcessus::where('organization_id', $orgId)->where('code', $processusCode)->first();
        if (! $processus) {
            return ['nb_groupes' => 0, 'nb_equipes' => 0, 'nb_automatiques' => 0, 'nb_sans_livreur' => 0, 'nb_vehicules_inactifs' => 0, 'par_categorie' => []];
        }

        ['groupes' => $groupes, 'automatiques' => $automatiques, 'signales' => $signales] = self::analyser(
            $orgId, $processus, $lignes, CommissionBaremeBrouillon::enCoursPour($orgId, $processus->id),
        );

        // Compteurs en ÉQUIPES (une équipe peut être concernée sur plusieurs catégories).
        return [
            'nb_groupes' => $groupes->count(),
            'nb_automatiques' => $automatiques->pluck('equipe_id')->unique()->count(),
            'nb_sans_livreur' => $signales->where('motif', 'sans_livreur')->pluck('equipe_id')->unique()->count(),
            'nb_vehicules_inactifs' => $signales->where('motif', 'vehicule_inactif')->pluck('equipe_id')->unique()->count(),
            'nb_equipes' => $groupes->pluck('equipe_id')->unique()->count(),
            'par_categorie' => $groupes->groupBy('categorie_nom')
                ->map(fn (Collection $g, string $nom) => ['categorie' => $nom, 'nb' => $g->count()])
                ->values()
                ->all(),
        ];
    }

    /**
     * (Équipe, catégorie) à reconfigurer À LA MAIN pour la configuration $lignes (équipes à
     * plusieurs livreurs actifs), avec leur état de préparation dans $brouillon.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return Collection<int, array<string, mixed>>
     */
    public static function groupes(string $orgId, CommissionProcessus $processus, array $lignes, ?CommissionBaremeBrouillon $brouillon): Collection
    {
        return self::analyser($orgId, $processus, $lignes, $brouillon)['groupes'];
    }

    /**
     * Analyse de l'impact de $lignes sur les partages — SOURCE UNIQUE des compteurs affichés et des
     * écritures : `groupes` (plusieurs livreurs actifs sur un véhicule actif, répartition à décider
     * dans la grille), `automatiques` (un seul livreur actif, sa part suit le barème) et `signales`
     * (sans livreur actif, ou véhicule inactif à plusieurs livreurs : non conformes, ni ajustés ni
     * bloquants).
     * Chargement groupé (équipes, membres, partages réels et préparés en quelques requêtes), pour
     * rester exploitable avec plusieurs centaines de véhicules.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array{groupes: Collection<int, array<string, mixed>>, automatiques: Collection<int, array{equipe_id: string, categorie_id: string, livreur_id: string, montant: int}>, signales: Collection<int, array{equipe_id: string, vehicule_nom: string, categorie_nom: string, motif: string}>}
     */
    public static function analyser(string $orgId, CommissionProcessus $processus, array $lignes, ?CommissionBaremeBrouillon $brouillon): array
    {
        $vide = ['groupes' => collect(), 'automatiques' => collect(), 'signales' => collect()];
        $categorieIds = collect($lignes)
            ->filter(fn (array $l) => in_array(CommissionCibleType::CODE_EQUIPE_LIVRAISON, $l['beneficiaires'] ?? [], true))
            ->pluck('categorie_id')
            ->unique()
            ->values();

        if ($categorieIds->isEmpty()) {
            return $vide;
        }

        // Toute équipe rattachée à un véhicule, quel que soit EquipeLivraison::is_active : ce drapeau
        // n'est lu que par le contrôle des distributions, jamais par la vente, le contrôle de partage
        // (COMM-015) ni la génération — le filtrer ici excluait des équipes en service (incident du
        // 25/09/2026 : 76 équipes sur 78 à is_active=false, dont des véhicules actifs qui vendent).
        $equipes = EquipeLivraison::with(['vehicule.typeVehicule', 'vehicule.site', 'membres.livreur'])
            ->where('organization_id', $orgId)
            ->whereHas('vehicule')
            ->get()
            ->filter(fn (EquipeLivraison $e) => in_array(
                $processus->code,
                CommissionProcessusDefaults::codesApplicablesPourVehicule($e->vehicule, CommissionRegleController::processusCodesDisponibles()),
                true,
            ))
            ->values();

        if ($equipes->isEmpty()) {
            return $vide;
        }

        $aujourdhui = Carbon::today();
        $categories = Categorie::whereIn('id', $categorieIds)->pluck('nom', 'id');
        $partagesReels = EquipeLivraisonPartageCategorie::where('processus_id', $processus->id)
            ->whereIn('equipe_id', $equipes->pluck('id'))
            ->whereIn('categorie_id', $categorieIds)
            ->actifA($aujourdhui)
            ->get()
            ->groupBy(fn (EquipeLivraisonPartageCategorie $p) => $p->equipe_id.'|'.$p->categorie_id);
        $partagesPrepares = $brouillon
            ? $brouillon->partages()->get()->groupBy(fn (CommissionBaremeBrouillonPartage $p) => $p->equipe_id.'|'.$p->categorie_id)
            : collect();

        $baremesEnVigueur = [];
        $groupes = [];
        $automatiques = [];
        $signales = [];

        foreach ($equipes as $equipe) {
            $vehicule = $equipe->vehicule;
            $typeId = $vehicule->type_vehicule_id;
            $membres = $equipe->membres->filter(fn (EquipeLivreur $m) => $m->livreur !== null)->values();
            $requis = $membres->filter(fn (EquipeLivreur $m) => $m->livreur->is_active)->pluck('livreur_id')->all();

            foreach ($categorieIds as $categorieId) {
                $cible = CommissionBaremeConfigurationService::baremeLivreurDepuisLignes($lignes, $categorieId, $typeId);
                if ($cible <= 0) {
                    continue;
                }

                $cleBareme = $categorieId.'|'.$typeId;
                $baremesEnVigueur[$cleBareme] ??= CommissionPartageLivraisonCategorieChecker::resoudreEnveloppe(
                    $orgId, $processus->id, $categorieId, $typeId, $aujourdhui,
                );
                if ($cible === $baremesEnVigueur[$cleBareme]) {
                    continue;
                }

                $cle = $equipe->id.'|'.$categorieId;
                $reel = $partagesReels->get($cle, collect());
                if (self::conforme($reel->map(fn ($p) => [$p->livreur_id, $p->montant_unitaire]), $cible, $requis) === null) {
                    continue;
                }

                // Décision du 25/09/2026 : un seul livreur actif → aucune répartition à décider, sa
                // part suit automatiquement le barème (versionnée à l'application du barème, cf.
                // appliquerAutomatiques()), quel que soit son partage actuel, même déjà faux.
                if (count($requis) === 1) {
                    $automatiques[] = [
                        'equipe_id' => $equipe->id,
                        'categorie_id' => $categorieId,
                        'livreur_id' => $requis[0],
                        'montant' => $cible,
                    ];

                    continue;
                }

                // Aucun livreur actif : anomalie (non conforme, bloquée à la commande) — signalée,
                // jamais ajustée. Plusieurs livreurs sur un véhicule INACTIF : il ne peut prendre
                // aucune commande ; signalé sans bloquer la publication, il restera refusé à la
                // commande (COMM-015) tant que son partage n'est pas corrigé.
                if (count($requis) === 0 || ! $vehicule->is_active) {
                    $signales[] = [
                        'equipe_id' => $equipe->id,
                        'vehicule_nom' => $vehicule->nom_vehicule,
                        'categorie_nom' => $categories->get($categorieId, $categorieId),
                        'motif' => count($requis) === 0 ? 'sans_livreur' : 'vehicule_inactif',
                    ];

                    continue;
                }

                $signature = self::signature($requis, $reel->pluck('id')->all());
                $prepare = $partagesPrepares->get($cle, collect());
                $montantsReels = $reel->pluck('montant_unitaire', 'livreur_id')->map(fn ($m) => (int) $m);
                $montantsPrepares = $prepare->pluck('montant_unitaire', 'livreur_id')->map(fn ($m) => (int) $m);

                [$statut, $motif] = match (true) {
                    $prepare->isEmpty() => [self::STATUT_A_CORRIGER, null],
                    $prepare->contains(fn (CommissionBaremeBrouillonPartage $p) => $p->signature_equipe !== $signature) => [
                        self::STATUT_A_REVALIDER,
                        'L\'équipe ou son partage a changé depuis la préparation : vérifiez et enregistrez à nouveau.',
                    ],
                    default => (function () use ($prepare, $cible, $requis) {
                        $erreur = self::conforme($prepare->map(fn ($p) => [$p->livreur_id, $p->montant_unitaire]), $cible, $requis);

                        return $erreur === null ? [self::STATUT_CONFORME, null] : [self::STATUT_A_CORRIGER, $erreur];
                    })(),
                };

                $lignesMembres = $membres->map(fn (EquipeLivreur $m) => [
                    'livreur_id' => $m->livreur_id,
                    'nom' => $m->livreur->nom_complet ?? $m->livreur_id,
                    'role' => $m->role,
                    'ordre' => (int) $m->ordre,
                    'requis' => in_array($m->livreur_id, $requis, true),
                    'actuel' => $montantsReels->get($m->livreur_id),
                    'prepare' => $montantsPrepares->get($m->livreur_id),
                ])->all();

                $groupes[] = [
                    'cle' => $cle,
                    'equipe_id' => $equipe->id,
                    'vehicule_id' => $vehicule->id,
                    'vehicule_nom' => $vehicule->nom_vehicule,
                    'immatriculation' => $vehicule->immatriculation,
                    'site_id' => $vehicule->site_id,
                    'site_nom' => $vehicule->site?->nom,
                    'type_vehicule_id' => $typeId,
                    'type_vehicule_nom' => $vehicule->typeVehicule?->nom,
                    'categorie_id' => $categorieId,
                    'categorie_nom' => $categories->get($categorieId, $categorieId),
                    'bareme_actuel' => $baremesEnVigueur[$cleBareme],
                    'bareme_cible' => $cible,
                    'total_actuel' => (int) $reel->sum('montant_unitaire'),
                    'membres' => self::avecProposition($lignesMembres, $cible),
                    'statut' => $statut,
                    'motif' => $motif,
                ];
            }
        }

        return [
            'groupes' => collect($groupes)
                ->sortBy(fn (array $g) => [$g['vehicule_nom'], $g['categorie_nom']])
                ->values(),
            'automatiques' => collect($automatiques),
            'signales' => collect($signales),
        ];
    }

    /**
     * Partage des équipes à un seul livreur actif, aligné sur le barème qui vient d'être appliqué
     * (même date d'effet) — jamais une équipe à plusieurs membres, dont la répartition reste une
     * décision explicite.
     *
     * @param  Collection<int, array{equipe_id: string, categorie_id: string, livreur_id: string, montant: int}>  $automatiques
     */
    private static function appliquerAutomatiques(string $processusId, Collection $automatiques): void
    {
        $dateEffet = Carbon::today();

        foreach ($automatiques as $a) {
            PartageLivraisonVersionService::versionner(
                $a['equipe_id'], $processusId, $a['categorie_id'], [$a['livreur_id'] => $a['montant']], $dateEffet,
            );
        }
    }

    /**
     * Enregistre dans le brouillon les partages préparés — atomique : si UNE saisie n'est pas
     * conforme (somme ≠ barème cible, membre actif sans part, livreur hors équipe) ou ne concerne
     * plus le brouillon, rien n'est enregistré et chaque erreur est rendue sous `groupes.{cle}`.
     *
     * @param  list<array{equipe_id: string, categorie_id: string, parts: list<array{livreur_id: string, montant_unitaire: int}>}>  $saisies
     */
    public static function enregistrerPartages(CommissionBaremeBrouillon $brouillon, array $saisies, ?string $userId): void
    {
        DB::transaction(function () use ($brouillon, $saisies, $userId) {
            $brouillon = CommissionBaremeBrouillon::whereKey($brouillon->id)->lockForUpdate()->firstOrFail();
            if (! $brouillon->estEnCours()) {
                throw ValidationException::withMessages(['brouillon' => 'Ce brouillon n\'est plus en cours.']);
            }

            $groupes = self::groupes($brouillon->organization_id, $brouillon->processus, $brouillon->lignes, $brouillon)->keyBy('cle');
            $erreurs = [];
            $aEcrire = [];

            foreach ($saisies as $saisie) {
                $cle = $saisie['equipe_id'].'|'.$saisie['categorie_id'];
                $groupe = $groupes->get($cle);

                if (! $groupe) {
                    $erreurs["groupes.{$cle}"] = 'Cette équipe n\'est plus concernée par ce changement de barème (rafraîchissez la page).';

                    continue;
                }

                $membres = collect($groupe['membres']);
                $horsEquipe = collect($saisie['parts'])->pluck('livreur_id')->diff($membres->pluck('livreur_id'));
                if ($horsEquipe->isNotEmpty()) {
                    $erreurs["groupes.{$cle}"] = 'Un livreur ne fait plus partie de l\'équipe (rafraîchissez la page).';

                    continue;
                }

                $parts = collect($saisie['parts'])->map(fn (array $p) => [$p['livreur_id'], $p['montant_unitaire']]);
                $requis = $membres->where('requis', true)->pluck('livreur_id')->all();
                $erreur = self::conforme($parts, $groupe['bareme_cible'], $requis, $membres->pluck('nom', 'livreur_id')->all());

                if ($erreur !== null) {
                    $erreurs["groupes.{$cle}"] = "{$groupe['vehicule_nom']} · {$groupe['categorie_nom']} : {$erreur}";

                    continue;
                }

                $aEcrire[] = [$groupe, $saisie['parts'], self::signatureGroupe($brouillon->processus_id, $groupe)];
            }

            if (! empty($erreurs)) {
                throw ValidationException::withMessages($erreurs);
            }

            foreach ($aEcrire as [$groupe, $parts, $signature]) {
                CommissionBaremeBrouillonPartage::where('brouillon_id', $brouillon->id)
                    ->where('equipe_id', $groupe['equipe_id'])
                    ->where('categorie_id', $groupe['categorie_id'])
                    ->delete();

                foreach ($parts as $p) {
                    CommissionBaremeBrouillonPartage::create([
                        'brouillon_id' => $brouillon->id,
                        'equipe_id' => $groupe['equipe_id'],
                        'categorie_id' => $groupe['categorie_id'],
                        'livreur_id' => $p['livreur_id'],
                        'montant_unitaire' => (int) $p['montant_unitaire'],
                        'signature_equipe' => $signature,
                        'updated_by' => $userId,
                    ]);
                }
            }

            $brouillon->update(['updated_by' => $userId]);
        });
    }

    /**
     * Publie barème + partages préparés dans UNE transaction, à la même date d'effet — ou rien :
     * refusée si la configuration des commissions a changé depuis la préparation, ou si une seule
     * (équipe, catégorie) concernée n'est pas conforme (non préparée, somme fausse, membre sans
     * part, équipe modifiée depuis). Tout est revérifié ici, jamais cru depuis l'écran.
     */
    public static function publier(CommissionBaremeBrouillon $brouillon, ?string $userId): void
    {
        DB::transaction(function () use ($brouillon, $userId) {
            $brouillon = CommissionBaremeBrouillon::whereKey($brouillon->id)->lockForUpdate()->firstOrFail();
            if (! $brouillon->estEnCours()) {
                throw ValidationException::withMessages(['publication' => 'Ce brouillon n\'est plus en cours.']);
            }

            $processus = $brouillon->processus;
            $orgId = $brouillon->organization_id;

            if (CommissionBaremeConfigurationService::signatureReglesActives($orgId, $processus->id) !== $brouillon->regles_signature) {
                throw ValidationException::withMessages([
                    'publication' => 'La configuration des commissions a été modifiée depuis la préparation de ce brouillon. Rouvrez Paramètres → Commissions et enregistrez à nouveau pour le mettre à jour.',
                ]);
            }

            ['groupes' => $groupes, 'automatiques' => $automatiques] = self::analyser($orgId, $processus, $brouillon->lignes, $brouillon);
            $nonConformes = $groupes->reject(fn (array $g) => $g['statut'] === self::STATUT_CONFORME);

            if ($nonConformes->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'publication' => sprintf(
                        'Publication impossible : %d partage(s) ne sont pas encore conformes au nouveau barème (%s%s).',
                        $nonConformes->count(),
                        $nonConformes->take(5)->map(fn (array $g) => "{$g['vehicule_nom']} · {$g['categorie_nom']}")->implode(', '),
                        $nonConformes->count() > 5 ? ', …' : '',
                    ),
                ]);
            }

            CommissionBaremeConfigurationService::appliquer($orgId, $processus->code, $brouillon->lignes, $userId);
            self::appliquerAutomatiques($processus->id, $automatiques);

            $dateEffet = Carbon::today();
            foreach ($groupes as $groupe) {
                $montants = collect($groupe['membres'])
                    ->filter(fn (array $m) => $m['prepare'] !== null)
                    ->mapWithKeys(fn (array $m) => [$m['livreur_id'] => $m['prepare']])
                    ->all();

                PartageLivraisonVersionService::versionner($groupe['equipe_id'], $processus->id, $groupe['categorie_id'], $montants, $dateEffet);
            }

            $brouillon->update([
                'statut' => CommissionBaremeBrouillon::STATUT_PUBLIE,
                'publie_par' => $userId,
                'publie_le' => now(),
                'updated_by' => $userId,
            ]);
        });
    }

    public static function abandonner(CommissionBaremeBrouillon $brouillon, ?string $userId): void
    {
        $brouillon->update([
            'statut' => CommissionBaremeBrouillon::STATUT_ABANDONNE,
            'abandonne_le' => now(),
            'updated_by' => $userId,
        ]);
    }

    /**
     * Répartition PROPOSÉE (jamais appliquée seule) : proportionnelle au partage réel des membres,
     * arrondie au GNF inférieur, le reliquat allant au premier chauffeur (à défaut, au premier
     * membre) — somme toujours exactement égale au barème cible. Aucune proposition sans partage
     * réel exploitable (total 0) : la saisie reste manuelle.
     *
     * @param  list<array<string, mixed>>  $membres
     * @return list<array<string, mixed>>
     */
    public static function avecProposition(array $membres, int $cible): array
    {
        $total = array_sum(array_map(fn (array $m) => (int) ($m['actuel'] ?? 0), $membres));

        if ($total <= 0 || empty($membres)) {
            return array_map(fn (array $m) => [...$m, 'proposition' => null], $membres);
        }

        $propositions = array_map(fn (array $m) => intdiv((int) ($m['actuel'] ?? 0) * $cible, $total), $membres);
        $reste = $cible - array_sum($propositions);

        $beneficiaire = 0;
        foreach ($membres as $index => $m) {
            if ($m['role'] === 'chauffeur') {
                $beneficiaire = $index;
                break;
            }
        }
        $propositions[$beneficiaire] += $reste;

        return array_map(
            fn (array $m, int $proposition) => [...$m, 'proposition' => $proposition],
            $membres,
            $propositions,
        );
    }

    /**
     * Message d'erreur du juge unique (null si conforme).
     *
     * @param  Collection<int, array{0: string, 1: int|string|null}>  $parts
     * @param  list<string>  $requis
     * @param  array<string, string>  $noms
     */
    private static function conforme(Collection $parts, int $cible, array $requis, array $noms = []): ?string
    {
        try {
            CommissionPartageLivraisonValidator::valider(
                $parts->map(fn (array $p) => (object) ['beneficiaire_id' => $p[0], 'montant_unitaire' => $p[1]]),
                $cible,
                $requis,
            );

            return null;
        } catch (InvalidArgumentException $e) {
            return strtr($e->getMessage(), $noms);
        }
    }

    /** @param  array<string, mixed>  $groupe */
    private static function signatureGroupe(string $processusId, array $groupe): string
    {
        $requis = collect($groupe['membres'])->where('requis', true)->pluck('livreur_id')->all();
        $reel = EquipeLivraisonPartageCategorie::where('processus_id', $processusId)
            ->where('equipe_id', $groupe['equipe_id'])
            ->where('categorie_id', $groupe['categorie_id'])
            ->actifA(Carbon::today())
            ->pluck('id')
            ->all();

        return self::signature($requis, $reel);
    }

    /**
     * @param  list<string>  $membresRequis
     * @param  list<string>  $partageReelIds
     */
    private static function signature(array $membresRequis, array $partageReelIds): string
    {
        sort($membresRequis);
        sort($partageReelIds);

        return hash('sha256', implode(',', $membresRequis).'|'.implode(',', $partageReelIds));
    }
}
