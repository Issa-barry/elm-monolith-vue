<?php

namespace App\Services\Contacts;

use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\Employe;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Ce qu'un utilisateur peut voir du référentiel Contacts (ADR 0060, lot 2, CONTACT-013 à 015).
 * Source unique pour les listes, les fiches et la recherche : la sécurité est décidée ici, côté
 * serveur, jamais par l'interface.
 *
 * - Une fonction est lisible si l'utilisateur a sa permission de lecture et, quand la fonction en
 *   dépend, si le module est actif (mêmes règles que la barre latérale et les routes).
 * - Un parrain n'est visible que dans le périmètre d'agences du lecteur (Parrain::visiblesPar).
 * - Une entreprise n'est visible que si au moins une de ses fonctions l'est ; un lien
 *   d'interlocuteur seulement si son entreprise l'est.
 * - Une personne n'est visible que si au moins une de ses fonctions l'est : sinon elle n'apparaît
 *   nulle part (liste, fiche, recherche, compteurs). Une personne sans fonction (compte seul)
 *   n'est jamais visible ici : elle se gère dans Utilisateurs.
 *
 * @phpstan-type Fonction array{fonction: string, id: string, actif: ?bool, sous_categorie: ?string, reference: ?string, entreprise_tierce_id?: string, au_nom_de?: ?string, sous_categories?: list<string>}
 */
final class VisibiliteContacts
{
    public const FONCTIONS = ['client', 'fournisseur', 'prestataire', 'proprietaire', 'parrain', 'livreur', 'employe', 'interlocuteur'];

    /** Condition toujours fausse : aucune ligne visible. */
    private const AUCUN = '1 = 0';

    /** Fonctions qu'une entreprise peut porter (propriétaire : lot 5 ; client professionnel : lot 7b). */
    public const FONCTIONS_ENTREPRISE = ['fournisseur', 'prestataire', 'proprietaire', 'client'];

    /** @var array<string, list<string>> */
    private array $lisibles = [];

    /** @return list<string> */
    public function fonctionsLisibles(User $lecteur): array
    {
        if (isset($this->lisibles[$lecteur->id])) {
            return $this->lisibles[$lecteur->id];
        }

        $org = $lecteur->organization;
        $module = fn (string $m) => $org !== null && ModuleService::isActive($m, $org);

        $regles = [
            'client' => $lecteur->can('clients.read'),
            'fournisseur' => $lecteur->can('fournisseurs.read') && $module(ModuleFeature::ACHATS),
            'prestataire' => $lecteur->can('prestataires.read') && $module(ModuleFeature::PRESTATAIRES),
            'proprietaire' => $lecteur->can('proprietaires.read') && $module(ModuleFeature::VEHICULES),
            'parrain' => $lecteur->can('parrains.read') && $module(ModuleFeature::VEHICULES),
            'livreur' => $lecteur->can('livreurs.read'),
            'employe' => $lecteur->can('rh-employes.read') && $module(ModuleFeature::RH),
        ];
        // Un lien d'interlocuteur n'a de sens que si son entreprise peut être visible.
        $regles['interlocuteur'] = $lecteur->can('contacts.read') && ($regles['fournisseur'] || $regles['prestataire'] || $regles['proprietaire'] || $regles['client']);

        return $this->lisibles[$lecteur->id] = array_keys(array_filter($regles));
    }

    public function peutLire(User $lecteur, string $fonction): bool
    {
        return in_array($fonction, $this->fonctionsLisibles($lecteur), true);
    }

    // ── Entreprises ─────────────────────────────────────────────────────────

    /** Entreprises de l'organisation dont au moins une fonction est lisible. */
    public function entreprisesVisibles(User $lecteur): Builder
    {
        $requete = EntrepriseTierce::query()->where('entreprises_tierces.organization_id', $lecteur->organization_id);
        $fonctions = array_values(array_intersect(self::FONCTIONS_ENTREPRISE, $this->fonctionsLisibles($lecteur)));

        if ($fonctions === []) {
            return $requete->whereRaw(self::AUCUN);
        }

        return $requete->where(function (Builder $q) use ($fonctions) {
            foreach ($fonctions as $fonction) {
                $q->orWhereExists(fn (QueryBuilder $s) => $this->fonctionDeLEntreprise($s, $fonction));
            }
        });
    }

    /** Restreint une requête d'entreprises à celles qui portent cette fonction (lisible). */
    public function entreprisesAvecFonction(Builder $requete, User $lecteur, string $fonction, ?string $code = null): Builder
    {
        if (! in_array($fonction, self::FONCTIONS_ENTREPRISE, true) || ! $this->peutLire($lecteur, $fonction)) {
            return $requete->whereRaw(self::AUCUN);
        }

        return $requete->whereExists(fn (QueryBuilder $s) => $this->fonctionDeLEntreprise($s, $fonction, $code));
    }

    public function entrepriseVisible(EntrepriseTierce $entreprise, User $lecteur): bool
    {
        return $this->entreprisesVisibles($lecteur)->whereKey($entreprise->getKey())->exists();
    }

    // ── Personnes ───────────────────────────────────────────────────────────

    /** Personnes de l'organisation dont au moins une fonction est lisible. */
    public function personnesVisibles(User $lecteur): Builder
    {
        $requete = Personne::query()->where('personnes.organization_id', $lecteur->organization_id);
        $fonctions = $this->fonctionsLisibles($lecteur);

        if ($fonctions === []) {
            return $requete->whereRaw(self::AUCUN);
        }

        return $requete->where(function (Builder $q) use ($fonctions, $lecteur) {
            foreach ($fonctions as $fonction) {
                $this->ouAvecFonction($q, $lecteur, $fonction);
            }
        });
    }

    /** Restreint une requête de personnes à celles qui portent cette fonction, si elle est lisible. */
    public function personnesAvecFonction(Builder $requete, User $lecteur, string $fonction, ?string $code = null): Builder
    {
        if (! $this->peutLire($lecteur, $fonction)) {
            return $requete->whereRaw(self::AUCUN);
        }

        return $requete->where(fn (Builder $q) => $this->ouAvecFonction($q, $lecteur, $fonction, $code));
    }

    public function personneVisible(Personne $personne, User $lecteur): bool
    {
        return $this->personnesVisibles($lecteur)->whereKey($personne->getKey())->exists();
    }

    // ── Recherche texte (listes et recherche globale) ───────────────────────

    /**
     * Nom, prénom, nom complet, surnom, e-mail, téléphone (chiffres seuls aussi), et désignation du
     * livreur si la fonction livreur est lisible. S'applique à une requête déjà restreinte par
     * personnesVisibles() : elle ne peut que réduire le résultat.
     */
    public function rechercherPersonnes(Builder $requete, string $terme, User $lecteur): Builder
    {
        $like = $this->like($terme);
        $chiffres = Personne::normaliserTelephone($terme);

        return $requete->where(function (Builder $q) use ($like, $chiffres, $lecteur) {
            foreach (['personnes.nom_complet', 'personnes.nom', 'personnes.prenom', 'personnes.surnom', 'personnes.email', 'personnes.telephone'] as $colonne) {
                $q->orWhere($colonne, 'like', $like);
            }
            if (strlen($chiffres) >= 3) {
                $q->orWhere('personnes.telephone_normalise', 'like', '%'.$chiffres.'%');
            }
            if ($this->peutLire($lecteur, 'livreur')) {
                $q->orWhereExists(fn (QueryBuilder $s) => $s->from('livreurs')
                    ->whereColumn('livreurs.personne_id', 'personnes.id')
                    ->whereNull('livreurs.deleted_at')
                    ->where('livreurs.nom_complet', 'like', $like));
            }
            // Lot 8 : nom et téléphone écrits sur la fiche Client (copie qui peut différer de la
            // Personne tant que la synchronisation n'est pas activée) — rien de ce que trouvait
            // l'ancien groupe « Clients » de la recherche globale n'est perdu.
            if ($this->peutLire($lecteur, 'client')) {
                $q->orWhereExists(fn (QueryBuilder $s) => $s->from('clients')
                    ->whereColumn('clients.personne_id', 'personnes.id')
                    ->whereNull('clients.deleted_at')
                    ->where(fn (QueryBuilder $c) => $c->where('clients.nom_complet', 'like', $like)
                        ->orWhere('clients.nom', 'like', $like)
                        ->orWhere('clients.prenom', 'like', $like)
                        ->orWhere('clients.telephone', 'like', $like)));
            }
        });
    }

    public function rechercherEntreprises(Builder $requete, string $terme): Builder
    {
        $like = $this->like($terme);
        $chiffres = Personne::normaliserTelephone($terme);

        return $requete->where(function (Builder $q) use ($like, $chiffres) {
            foreach (['entreprises_tierces.raison_sociale', 'entreprises_tierces.nom_commercial', 'entreprises_tierces.identifiant_fiscal', 'entreprises_tierces.rccm', 'entreprises_tierces.email', 'entreprises_tierces.telephone'] as $colonne) {
                $q->orWhere($colonne, 'like', $like);
            }
            if (strlen($chiffres) >= 3) {
                $q->orWhere('entreprises_tierces.telephone_normalise', 'like', '%'.$chiffres.'%');
            }
        });
    }

    /** Ordre d'affichage : nom saisi, sinon prénom, nom, surnom, téléphone. */
    public function trierPersonnes(Builder $requete): Builder
    {
        return $requete->orderByRaw("COALESCE(NULLIF(personnes.nom_complet, ''), NULLIF(personnes.prenom, ''), NULLIF(personnes.nom, ''), NULLIF(personnes.surnom, ''), personnes.telephone)")
            ->orderBy('personnes.id');
    }

    private function like(string $terme): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($terme)).'%';
    }

    // ── Fonctions d'une page de résultats ───────────────────────────────────

    /**
     * Fonctions lisibles de chaque personne, en une requête par fonction (pas une par personne).
     *
     * @param  Collection<int, Personne>  $personnes
     * @return array<string, list<Fonction>>
     */
    public function fonctionsDesPersonnes(Collection $personnes, User $lecteur): array
    {
        $ids = $personnes->pluck('id')->all();
        $resultat = array_fill_keys($ids, []);
        if ($ids === []) {
            return $resultat;
        }

        $ajouter = function (string $fonction, iterable $lignes, callable $versLigne) use (&$resultat) {
            foreach ($lignes as $ligne) {
                $resultat[$ligne->personne_id][] = ['fonction' => $fonction, ...$versLigne($ligne)];
            }
        };

        if ($this->peutLire($lecteur, 'client')) {
            // Lot 7b : un client professionnel apparaît sur la fiche de son contact « au nom de » l'entreprise.
            $ajouter('client', Client::with('entrepriseTierce:id,raison_sociale')->whereIn('personne_id', $ids)->where('organization_id', $lecteur->organization_id)->orderBy('created_at')->get(),
                fn (Client $c) => ['id' => $c->id, 'actif' => $c->is_active, 'sous_categorie' => $c->type?->value, 'reference' => null,
                    ...($c->entreprise_tierce_id !== null ? ['entreprise_tierce_id' => $c->entreprise_tierce_id, 'au_nom_de' => $c->entrepriseTierce?->raison_sociale] : [])]);
        }
        if ($this->peutLire($lecteur, 'fournisseur')) {
            $ajouter('fournisseur', Fournisseur::with('sousCategories')->whereIn('personne_id', $ids)->where('organization_id', $lecteur->organization_id)->orderBy('created_at')->get(),
                fn (Fournisseur $f) => ['id' => $f->id, 'actif' => $f->is_active, 'sous_categorie' => null, 'reference' => $f->reference, 'sous_categories' => $this->libellesSousCategories($f)]);
        }
        if ($this->peutLire($lecteur, 'prestataire')) {
            $ajouter('prestataire', Prestataire::with('sousCategories')->whereIn('personne_id', $ids)->where('organization_id', $lecteur->organization_id)->orderBy('created_at')->get(),
                fn (Prestataire $p) => ['id' => $p->id, 'actif' => $p->is_active, 'reference' => $p->reference, ...$this->qualifications($p)]);
        }
        if ($this->peutLire($lecteur, 'proprietaire')) {
            // D10 (lot 5) : le contact d'un propriétaire entreprise garde la fonction, « au nom de »
            // l'entreprise — visible du même lecteur, puisqu'elle porte cette fonction.
            $ajouter('proprietaire', Proprietaire::with('entrepriseTierce:id,raison_sociale')->whereIn('personne_id', $ids)->where('organization_id', $lecteur->organization_id)->orderBy('created_at')->get(),
                fn (Proprietaire $p) => ['id' => $p->id, 'actif' => $p->is_active, 'sous_categorie' => $p->type?->value, 'reference' => null,
                    ...($p->entreprise_tierce_id !== null ? ['entreprise_tierce_id' => $p->entreprise_tierce_id, 'au_nom_de' => $p->entrepriseTierce?->raison_sociale] : [])]);
        }
        if ($this->peutLire($lecteur, 'parrain')) {
            $ajouter('parrain', Parrain::visiblesPar($lecteur)->whereIn('parrains.personne_id', $ids)->orderBy('parrains.created_at')->get(),
                fn (Parrain $p) => ['id' => $p->id, 'actif' => $p->is_active, 'sous_categorie' => null, 'reference' => null]);
        }
        if ($this->peutLire($lecteur, 'livreur')) {
            $ajouter('livreur', Livreur::whereIn('personne_id', $ids)->where('organization_id', $lecteur->organization_id)->orderBy('created_at')->get(),
                fn (Livreur $l) => ['id' => $l->id, 'actif' => $l->is_active, 'sous_categorie' => null, 'reference' => $l->nom_complet]);
        }
        if ($this->peutLire($lecteur, 'employe')) {
            $ajouter('employe', Employe::whereIn('personne_id', $ids)->where('organization_id', $lecteur->organization_id)->orderBy('created_at')->get(),
                fn (Employe $e) => ['id' => $e->id, 'actif' => null, 'sous_categorie' => $e->statut?->value, 'reference' => $e->matricule]);
        }
        if ($this->peutLire($lecteur, 'interlocuteur')) {
            $ajouter('interlocuteur', EntrepriseInterlocuteur::whereIn('personne_id', $ids)
                ->whereIn('entreprise_tierce_id', $this->entreprisesVisibles($lecteur)->select('entreprises_tierces.id'))
                ->orderBy('created_at')->get(),
                fn (EntrepriseInterlocuteur $i) => ['id' => $i->id, 'actif' => $i->is_active, 'sous_categorie' => null, 'reference' => $i->fonction, 'entreprise_tierce_id' => $i->entreprise_tierce_id]);
        }

        return $resultat;
    }

    /**
     * @param  Collection<int, EntrepriseTierce>  $entreprises
     * @return array<string, list<Fonction>>
     */
    public function fonctionsDesEntreprises(Collection $entreprises, User $lecteur): array
    {
        $ids = $entreprises->pluck('id')->all();
        $resultat = array_fill_keys($ids, []);
        if ($ids === []) {
            return $resultat;
        }

        if ($this->peutLire($lecteur, 'fournisseur')) {
            foreach (Fournisseur::with('sousCategories')->whereIn('entreprise_tierce_id', $ids)->where('organization_id', $lecteur->organization_id)->orderBy('created_at')->get() as $f) {
                $resultat[$f->entreprise_tierce_id][] = ['fonction' => 'fournisseur', 'id' => $f->id, 'actif' => $f->is_active, 'sous_categorie' => null, 'reference' => $f->reference, 'sous_categories' => $this->libellesSousCategories($f)];
            }
        }
        if ($this->peutLire($lecteur, 'prestataire')) {
            foreach (Prestataire::with('sousCategories')->whereIn('entreprise_tierce_id', $ids)->where('organization_id', $lecteur->organization_id)->orderBy('created_at')->get() as $p) {
                $resultat[$p->entreprise_tierce_id][] = ['fonction' => 'prestataire', 'id' => $p->id, 'actif' => $p->is_active, 'reference' => $p->reference, ...$this->qualifications($p)];
            }
        }
        if ($this->peutLire($lecteur, 'proprietaire')) {
            foreach (Proprietaire::whereIn('entreprise_tierce_id', $ids)->where('organization_id', $lecteur->organization_id)->orderBy('created_at')->get() as $p) {
                $resultat[$p->entreprise_tierce_id][] = ['fonction' => 'proprietaire', 'id' => $p->id, 'actif' => $p->is_active, 'sous_categorie' => null, 'reference' => null];
            }
        }
        if ($this->peutLire($lecteur, 'client')) {
            foreach (Client::whereIn('entreprise_tierce_id', $ids)->where('organization_id', $lecteur->organization_id)->orderBy('created_at')->get() as $c) {
                $resultat[$c->entreprise_tierce_id][] = ['fonction' => 'client', 'id' => $c->id, 'actif' => $c->is_active, 'sous_categorie' => $c->type?->value, 'reference' => null];
            }
        }

        return $resultat;
    }

    /**
     * Lot 6b : qualifications d'un prestataire (libellés du référentiel) ; repli sur l'ancien
     * `type` tant qu'aucune n'est enregistrée.
     *
     * @return array{sous_categorie: ?string, sous_categories?: list<string>}
     */
    private function qualifications(Prestataire $prestataire): array
    {
        if ($prestataire->sousCategories->isEmpty()) {
            return ['sous_categorie' => $prestataire->type?->value];
        }

        return ['sous_categorie' => null, 'sous_categories' => $this->libellesSousCategories($prestataire)];
    }

    /** Lot 6a : sous-catégories d'un fournisseur (sans effet métier), désactivées comprises. */
    private function libellesSousCategories(Fournisseur|Prestataire $fonction): array
    {
        return $fonction->sousCategories->sortBy([['ordre', 'asc'], ['libelle', 'asc']])->pluck('libelle')->values()->all();
    }

    // ── Sous-requêtes ───────────────────────────────────────────────────────

    private function ouAvecFonction(Builder $q, User $lecteur, string $fonction, ?string $code = null): void
    {
        if ($fonction === 'parrain') {
            $q->orWhereIn('personnes.id', Parrain::visiblesPar($lecteur)->select('parrains.personne_id'));

            return;
        }

        if ($fonction === 'interlocuteur') {
            $q->orWhereExists(fn (QueryBuilder $s) => $s->from('entreprise_interlocuteurs')
                ->whereColumn('entreprise_interlocuteurs.personne_id', 'personnes.id')
                ->whereIn('entreprise_interlocuteurs.entreprise_tierce_id', $this->entreprisesVisibles($lecteur)->select('entreprises_tierces.id')));

            return;
        }

        [$table, $colonneCode] = match ($fonction) {
            'client' => ['clients', 'type'],
            'fournisseur' => ['fournisseurs', null],
            'prestataire' => ['prestataires', null],
            'proprietaire' => ['proprietaires', 'type'],
            'livreur' => ['livreurs', null],
            'employe' => ['employes', null],
        };

        $q->orWhereExists(function (QueryBuilder $s) use ($table, $colonneCode, $code, $fonction) {
            $s->from($table)
                ->whereColumn("{$table}.personne_id", 'personnes.id')
                ->whereColumn("{$table}.organization_id", 'personnes.organization_id')
                ->whereNull("{$table}.deleted_at");

            if ($code !== null) {
                $this->filtrerSousCategorie($s, $table, $fonction, $colonneCode, $code);
            }
        });
    }

    private function fonctionDeLEntreprise(QueryBuilder $s, string $fonction, ?string $code = null): QueryBuilder
    {
        $table = match ($fonction) {
            'fournisseur' => 'fournisseurs',
            'prestataire' => 'prestataires',
            'proprietaire' => 'proprietaires',
            'client' => 'clients',
        };

        $s->from($table)
            ->whereColumn("{$table}.entreprise_tierce_id", 'entreprises_tierces.id')
            ->whereColumn("{$table}.organization_id", 'entreprises_tierces.organization_id')
            ->whereNull("{$table}.deleted_at");

        if ($code !== null) {
            $this->filtrerSousCategorie($s, $table, $fonction, $fonction === 'client' ? 'type' : null, $code);
        }

        return $s;
    }

    /**
     * Sous-catégorie : nature client lue sur sa colonne (CONTACT-008) ; fournisseur et prestataire
     * (lot 6b) sur leur table de liaison, le prestataire avec le repli sur l'ancien `type` tant
     * qu'aucune qualification n'est enregistrée.
     */
    private function filtrerSousCategorie(QueryBuilder $s, string $table, string $fonction, ?string $colonneCode, string $code): void
    {
        if ($colonneCode !== null) {
            $s->where("{$table}.{$colonneCode}", $code);

            return;
        }

        if ($fonction === 'fournisseur') {
            $s->whereExists(fn (QueryBuilder $p) => $p->from('fournisseur_sous_categories')
                ->join('contact_sous_categories', 'contact_sous_categories.id', '=', 'fournisseur_sous_categories.contact_sous_categorie_id')
                ->whereColumn('fournisseur_sous_categories.fournisseur_id', 'fournisseurs.id')
                ->where('contact_sous_categories.code', $code));

            return;
        }

        if ($fonction === 'prestataire') {
            $qualifications = fn (QueryBuilder $p) => $p->from('prestataire_sous_categories')
                ->whereColumn('prestataire_sous_categories.prestataire_id', 'prestataires.id');
            $s->where(fn (QueryBuilder $w) => $w
                ->whereExists(fn (QueryBuilder $p) => $qualifications($p)
                    ->join('contact_sous_categories', 'contact_sous_categories.id', '=', 'prestataire_sous_categories.contact_sous_categorie_id')
                    ->where('contact_sous_categories.code', $code))
                ->orWhere(fn (QueryBuilder $repli) => $repli
                    ->whereNotExists(fn (QueryBuilder $p) => $qualifications($p))
                    ->where('prestataires.type', $code)));

            return;
        }

        $s->whereRaw(self::AUCUN);
    }
}
