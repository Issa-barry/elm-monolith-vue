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

            $groupes = self::groupes($orgId, $processus, $lignes, $brouillon);

            if ($groupes->isEmpty()) {
                CommissionBaremeConfigurationService::appliquer($orgId, $processusCode, $lignes, $userId);
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
     * @return array{nb_groupes: int, nb_equipes: int, par_categorie: list<array{categorie: string, nb: int}>}
     */
    public static function apercu(string $orgId, string $processusCode, array $lignes): array
    {
        $processus = CommissionProcessus::where('organization_id', $orgId)->where('code', $processusCode)->first();
        if (! $processus) {
            return ['nb_groupes' => 0, 'nb_equipes' => 0, 'par_categorie' => []];
        }

        $groupes = self::groupes($orgId, $processus, $lignes, CommissionBaremeBrouillon::enCoursPour($orgId, $processus->id));

        return [
            'nb_groupes' => $groupes->count(),
            'nb_equipes' => $groupes->pluck('equipe_id')->unique()->count(),
            'par_categorie' => $groupes->groupBy('categorie_nom')
                ->map(fn (Collection $g, string $nom) => ['categorie' => $nom, 'nb' => $g->count()])
                ->values()
                ->all(),
        ];
    }

    /**
     * (Équipe, catégorie) concernées par la configuration $lignes, avec leur état de préparation
     * dans $brouillon. Chargement groupé (équipes, membres, partages réels et préparés en quelques
     * requêtes), pour rester exploitable avec plusieurs centaines de véhicules.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return Collection<int, array<string, mixed>>
     */
    public static function groupes(string $orgId, CommissionProcessus $processus, array $lignes, ?CommissionBaremeBrouillon $brouillon): Collection
    {
        $categorieIds = collect($lignes)
            ->filter(fn (array $l) => in_array(CommissionCibleType::CODE_EQUIPE_LIVRAISON, $l['beneficiaires'] ?? [], true))
            ->pluck('categorie_id')
            ->unique()
            ->values();

        if ($categorieIds->isEmpty()) {
            return collect();
        }

        $equipes = EquipeLivraison::with(['vehicule.typeVehicule', 'vehicule.site', 'membres.livreur'])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->whereHas('vehicule')
            ->get()
            ->filter(fn (EquipeLivraison $e) => in_array(
                $processus->code,
                CommissionProcessusDefaults::codesApplicablesPourVehicule($e->vehicule, CommissionRegleController::processusCodesDisponibles()),
                true,
            ))
            ->values();

        if ($equipes->isEmpty()) {
            return collect();
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

        return collect($groupes)
            ->sortBy(fn (array $g) => [$g['vehicule_nom'], $g['categorie_nom']])
            ->values();
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

            $groupes = self::groupes($orgId, $processus, $brouillon->lignes, $brouillon);
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
