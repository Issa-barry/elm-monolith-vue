<?php

namespace App\Services\Contacts;

use App\Enums\AuditEvent;
use App\Enums\ContactCategorie;
use App\Models\CategorieLibre;
use App\Models\ContactSousCategorie;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Personne;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Paramétrage du référentiel Contacts (ADR 0060, lot 6a, CONTACT-033 à CONTACT-037).
 *
 * - Sous-catégories : celles des fournisseurs (lot 6a) et des prestataires (lot 6b, qualifications)
 *   s'administrent ; celles des clients restent en lecture seule tant que ventes et PDV affichent des
 *   libellés écrits en dur. Une sous-catégorie système (Consultant, natures client) ne se renomme
 *   pas, ne se désactive pas et ne change jamais de code (décision C, D18).
 * - Catégories libres (ex. Partenaire) : créées, renommées, réordonnées, désactivées ; posées sur
 *   une fiche Personne ou Entreprise ; aucun effet sur les ventes, commissions, comptabilité,
 *   logistique ni permissions.
 * - Code généré une fois depuis le libellé, jamais modifié ; libellé unique, sans distinction de
 *   casse, par organisation (et par fonction pour une sous-catégorie).
 * - Rien ne se supprime : un élément désactivé n'est plus proposé mais reste sur les fiches qui le
 *   portent, et ces fiches restent enregistrables.
 * - Journal d'audit : identifiants et noms de champs seulement.
 */
final class ReferentielCategories
{
    /**
     * Fonctions dont les sous-catégories s'administrent : Fournisseur (lot 6a), Prestataire (lot 6b,
     * décision Q3 — Consultant reste protégé). Client reste en lecture seule.
     */
    public const SOUS_CATEGORIES_ADMINISTRABLES = [ContactCategorie::FOURNISSEUR, ContactCategorie::PRESTATAIRE];

    /** Fonctions présentées sur l'écran, dans cet ordre. */
    public const FONCTIONS_AFFICHEES = [ContactCategorie::CLIENT, ContactCategorie::FOURNISSEUR, ContactCategorie::PRESTATAIRE];

    public const LISTE_LIBRES = 'libres';

    public function __construct(private readonly AuditLogService $journal) {}

    // ── Sous-catégories ─────────────────────────────────────────────────────

    public function creerSousCategorie(User $acteur, ContactCategorie $categorie, string $libelle): ContactSousCategorie
    {
        $this->assertAdministrable($categorie);
        $libelle = $this->libelle($libelle);

        return $this->creer(function () use ($acteur, $categorie, $libelle) {
            $existantes = ContactSousCategorie::where('organization_id', $acteur->organization_id)
                ->where('categorie', $categorie->value)
                ->lockForUpdate()
                ->get(['id', 'code', 'libelle', 'ordre']);
            $this->assertLibelleLibre($existantes, $libelle, 'Une sous-catégorie porte déjà ce libellé pour cette fonction.');

            $sousCategorie = ContactSousCategorie::create([
                'organization_id' => $acteur->organization_id,
                'categorie' => $categorie->value,
                'code' => $this->codeLibre($libelle, $existantes->pluck('code')->all()),
                'libelle' => $libelle,
                'is_active' => true,
                'ordre' => (int) $existantes->max('ordre') + 1,
            ]);
            $this->journal->record($sousCategorie, AuditEvent::CREATED, $acteur, null, null, ['categorie' => $categorie->value]);

            return $sousCategorie;
        });
    }

    /** @param  array{libelle?: string, is_active?: bool}  $donnees */
    public function modifierSousCategorie(User $acteur, ContactSousCategorie $sousCategorie, array $donnees): ContactSousCategorie
    {
        $this->assertMemeOrganisation($sousCategorie, $acteur);
        $this->assertAdministrable($sousCategorie->categorie);
        if ($sousCategorie->is_system) {
            throw ValidationException::withMessages(['libelle' => 'Une sous-catégorie système ne se renomme pas et ne se désactive pas.']);
        }

        return DB::transaction(function () use ($acteur, $sousCategorie, $donnees) {
            if (array_key_exists('libelle', $donnees)) {
                $libelle = $this->libelle($donnees['libelle']);
                $autres = ContactSousCategorie::where('organization_id', $sousCategorie->organization_id)
                    ->where('categorie', $sousCategorie->categorie->value)
                    ->whereKeyNot($sousCategorie->id)
                    ->lockForUpdate()
                    ->get(['id', 'libelle']);
                $this->assertLibelleLibre($autres, $libelle, 'Une sous-catégorie porte déjà ce libellé pour cette fonction.');
                $sousCategorie->libelle = $libelle;
            }
            if (array_key_exists('is_active', $donnees)) {
                $sousCategorie->is_active = (bool) $donnees['is_active'];
            }

            $this->enregistrer($sousCategorie, $acteur);

            return $sousCategorie;
        });
    }

    // ── Catégories libres ───────────────────────────────────────────────────

    public function creerCategorieLibre(User $acteur, string $libelle): CategorieLibre
    {
        $libelle = $this->libelle($libelle);

        return $this->creer(function () use ($acteur, $libelle) {
            $existantes = CategorieLibre::where('organization_id', $acteur->organization_id)
                ->lockForUpdate()
                ->get(['id', 'code', 'libelle', 'ordre']);
            $this->assertLibelleLibre($existantes, $libelle, 'Une catégorie porte déjà ce libellé.');

            $categorie = CategorieLibre::create([
                'organization_id' => $acteur->organization_id,
                'code' => $this->codeLibre($libelle, $existantes->pluck('code')->all()),
                'libelle' => $libelle,
                'is_active' => true,
                'ordre' => (int) $existantes->max('ordre') + 1,
            ]);
            $this->journal->record($categorie, AuditEvent::CREATED, $acteur);

            return $categorie;
        });
    }

    /** @param  array{libelle?: string, is_active?: bool}  $donnees */
    public function modifierCategorieLibre(User $acteur, CategorieLibre $categorie, array $donnees): CategorieLibre
    {
        $this->assertMemeOrganisation($categorie, $acteur);

        return DB::transaction(function () use ($acteur, $categorie, $donnees) {
            if (array_key_exists('libelle', $donnees)) {
                $libelle = $this->libelle($donnees['libelle']);
                $autres = CategorieLibre::where('organization_id', $categorie->organization_id)
                    ->whereKeyNot($categorie->id)
                    ->lockForUpdate()
                    ->get(['id', 'libelle']);
                $this->assertLibelleLibre($autres, $libelle, 'Une catégorie porte déjà ce libellé.');
                $categorie->libelle = $libelle;
            }
            if (array_key_exists('is_active', $donnees)) {
                $categorie->is_active = (bool) $donnees['is_active'];
            }

            $this->enregistrer($categorie, $acteur);

            return $categorie;
        });
    }

    // ── Ordre ───────────────────────────────────────────────────────────────

    /**
     * Nouvel ordre d'une liste : `libres`, ou une fonction administrable. Les ids doivent être
     * exactement ceux de la liste (désactivés compris).
     *
     * @param  list<string>  $ids
     */
    public function reordonner(User $acteur, string $liste, array $ids): void
    {
        if ($liste !== self::LISTE_LIBRES) {
            $this->assertAdministrable(ContactCategorie::tryFrom($liste));
        }

        DB::transaction(function () use ($acteur, $liste, $ids) {
            $requete = $liste === self::LISTE_LIBRES
                ? CategorieLibre::where('organization_id', $acteur->organization_id)
                : ContactSousCategorie::where('organization_id', $acteur->organization_id)->where('categorie', $liste);
            $actuels = $requete->lockForUpdate()->pluck('id')->all();

            if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $actuels) !== [] || array_diff($actuels, $ids) !== []) {
                throw ValidationException::withMessages(['ids' => 'La liste a changé entre-temps : rechargez la page puis recommencez.']);
            }

            $classe = $liste === self::LISTE_LIBRES ? CategorieLibre::class : ContactSousCategorie::class;
            foreach (array_values($ids) as $position => $id) {
                $classe::whereKey($id)->update(['ordre' => $position + 1]);
            }
        });
    }

    // ── Attribution des catégories libres ───────────────────────────────────

    /**
     * Remplace les catégories libres d'une fiche. La visibilité de la fiche et la permission sont
     * vérifiées par le contrôleur ; ici : organisation, catégories de l'organisation, une catégorie
     * désactivée peut être gardée mais pas ajoutée.
     *
     * @param  list<string>  $ids
     * @return array{ajoutees: list<string>, retirees: list<string>}
     */
    public function attribuer(User $acteur, Personne|EntrepriseTierce $fiche, array $ids): array
    {
        $this->assertMemeOrganisation($fiche, $acteur);

        return DB::transaction(function () use ($acteur, $fiche, $ids) {
            $fiche::whereKey($fiche->getKey())->lockForUpdate()->first();
            $relation = $fiche->categoriesLibres();
            $actuelles = $relation->pluck('contact_categories.id')->all();

            $demandees = array_values(array_unique($ids));
            $valides = CategorieLibre::where('organization_id', $fiche->organization_id)
                ->whereIn('id', $demandees)
                ->get(['id', 'is_active']);
            if ($valides->count() !== count($demandees)) {
                throw ValidationException::withMessages(['categorie_ids' => 'Une catégorie choisie est introuvable.']);
            }
            $ajoutees = array_values(array_diff($demandees, $actuelles));
            if ($valides->whereIn('id', $ajoutees)->contains(fn (CategorieLibre $c) => ! $c->is_active)) {
                throw ValidationException::withMessages(['categorie_ids' => 'Une catégorie désactivée ne peut plus être ajoutée.']);
            }
            $retirees = array_values(array_diff($actuelles, $demandees));

            if ($ajoutees === [] && $retirees === []) {
                return ['ajoutees' => [], 'retirees' => []];
            }

            $relation->detach($retirees);
            $relation->attach(array_fill_keys($ajoutees, ['created_by' => $acteur->id]));
            $this->journal->record($fiche, AuditEvent::UPDATED, $acteur, null, null, [
                'origine' => 'categories_libres',
                'categories_ajoutees' => $ajoutees,
                'categories_retirees' => $retirees,
            ]);

            return ['ajoutees' => $ajoutees, 'retirees' => $retirees];
        });
    }

    // ── Sous-catégories d'un fournisseur ────────────────────────────────────

    /**
     * Sous-catégories d'un fournisseur (aucun effet métier). À appeler dans la transaction du
     * formulaire. Une sous-catégorie désactivée peut être gardée mais pas ajoutée.
     *
     * @param  list<string>  $ids
     */
    public function synchroniserFournisseur(User $acteur, Fournisseur $fournisseur, array $ids): void
    {
        if ($fournisseur->organization_id !== $acteur->organization_id) {
            throw new NotFoundHttpException;
        }

        $relation = $fournisseur->sousCategories();
        $actuelles = $relation->pluck('contact_sous_categories.id')->all();
        $demandees = array_values(array_unique($ids));
        $valides = ContactSousCategorie::where('organization_id', $fournisseur->organization_id)
            ->where('categorie', ContactCategorie::FOURNISSEUR->value)
            ->whereIn('id', $demandees)
            ->get(['id', 'is_active']);
        if ($valides->count() !== count($demandees)) {
            throw ValidationException::withMessages(['sous_categorie_ids' => 'Une sous-catégorie choisie est introuvable.']);
        }
        $ajoutees = array_values(array_diff($demandees, $actuelles));
        if ($valides->whereIn('id', $ajoutees)->contains(fn (ContactSousCategorie $s) => ! $s->is_active)) {
            throw ValidationException::withMessages(['sous_categorie_ids' => 'Une sous-catégorie désactivée ne peut plus être ajoutée.']);
        }
        $retirees = array_values(array_diff($actuelles, $demandees));

        if ($ajoutees === [] && $retirees === []) {
            return;
        }

        $relation->detach($retirees);
        $relation->attach(array_fill_keys($ajoutees, ['created_by' => $acteur->id]));
        $this->journal->record($fournisseur, AuditEvent::UPDATED, $acteur, null, null, [
            'origine' => 'sous_categories',
            'sous_categories_ajoutees' => $ajoutees,
            'sous_categories_retirees' => $retirees,
        ]);
    }

    /**
     * Sous-catégories Fournisseur d'un formulaire (écran Fournisseur, parcours Contacts — lot 8).
     *
     * @param  list<string>  $portees
     * @return list<array{value: string, label: string, actif: bool}>
     */
    public static function optionsSousCategoriesFournisseur(?string $organizationId, array $portees = []): array
    {
        return self::options(
            ContactSousCategorie::where('organization_id', $organizationId)->where('categorie', ContactCategorie::FOURNISSEUR->value)->get(),
            $portees,
        );
    }

    /**
     * Options d'un formulaire : éléments actifs, plus ceux déjà portés même désactivés.
     *
     * @param  Collection<int, ContactSousCategorie|CategorieLibre>  $elements
     * @param  list<string>  $portes
     * @return list<array{value: string, label: string, actif: bool}>
     */
    public static function options(Collection $elements, array $portes = []): array
    {
        return $elements
            ->filter(fn ($e) => $e->is_active || in_array($e->id, $portes, true))
            ->sortBy([['ordre', 'asc'], ['libelle', 'asc']])
            ->map(fn ($e) => ['value' => $e->id, 'label' => $e->is_active ? $e->libelle : "{$e->libelle} (désactivée)", 'actif' => $e->is_active])
            ->values()
            ->all();
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    private function creer(callable $creation): Model
    {
        try {
            return DB::transaction($creation);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['libelle' => 'Cet élément vient d\'être créé par un autre utilisateur : rechargez la page.']);
        }
    }

    private function enregistrer(ContactSousCategorie|CategorieLibre $element, User $acteur): void
    {
        $champs = array_keys($element->getDirty());
        if ($champs === []) {
            return;
        }

        $statutChange = array_key_exists('is_active', $element->getDirty());
        $element->save();
        $this->journal->record($element, $statutChange && count($champs) === 1 ? AuditEvent::STATUS_CHANGED : AuditEvent::UPDATED, $acteur,
            $statutChange ? ['is_active' => ! $element->is_active] : null,
            $statutChange ? ['is_active' => $element->is_active] : null,
            ['champs_modifies' => $champs]);
    }

    private function assertAdministrable(?ContactCategorie $categorie): void
    {
        if ($categorie === null || ! in_array($categorie, self::SOUS_CATEGORIES_ADMINISTRABLES, true)) {
            throw ValidationException::withMessages(['categorie' => 'Les sous-catégories de cette fonction ne se modifient pas depuis cet écran.']);
        }
    }

    private function assertMemeOrganisation(Model $element, User $acteur): void
    {
        if ($element->getAttribute('organization_id') === null || $element->getAttribute('organization_id') !== $acteur->organization_id) {
            throw new NotFoundHttpException;
        }
    }

    /** @param  Collection<int, Model>  $existants */
    private function assertLibelleLibre(Collection $existants, string $libelle, string $message): void
    {
        $cle = mb_strtolower($libelle);
        if ($existants->contains(fn ($e) => mb_strtolower((string) $e->libelle) === $cle)) {
            throw ValidationException::withMessages(['libelle' => $message]);
        }
    }

    private function libelle(string $valeur): string
    {
        $libelle = trim(preg_replace('/\s+/u', ' ', $valeur) ?? '');
        if ($libelle === '') {
            throw ValidationException::withMessages(['libelle' => 'Le libellé est obligatoire.']);
        }
        if (mb_strlen($libelle) > 100) {
            throw ValidationException::withMessages(['libelle' => 'Le libellé ne dépasse pas 100 caractères.']);
        }

        return $libelle;
    }

    /** @param  list<string>  $pris */
    private function codeLibre(string $libelle, array $pris): string
    {
        $base = Str::limit(Str::slug($libelle, '_'), 44, '') ?: 'categorie';
        $code = $base;
        for ($n = 2; in_array($code, $pris, true); $n++) {
            $code = "{$base}_{$n}";
        }

        return $code;
    }
}
