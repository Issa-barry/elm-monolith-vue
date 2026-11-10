<?php

namespace App\Services\Contacts;

use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\User;
use App\Support\Contacts\PresentationContacts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Création d'une fonction sur une fiche du référentiel Contacts (ADR 0060, lot 3).
 *
 * - Fonctions créables dans ce lot : Fournisseur et Prestataire (décision 2 du lot 3). Il faut la
 *   permission de création de la fonction et qu'elle soit lisible (permission de lecture et module
 *   actif) : on ne crée pas ce qu'on ne pourrait pas voir ensuite.
 * - Une seule fonction de chaque catégorie par fiche (décision 11, CONTACT-018), active ou non ;
 *   contrôlée sous verrou de la fiche (SELECT ... FOR UPDATE) pour que deux ajouts simultanés ne
 *   créent pas deux fonctions.
 * - Les ids des fonctions existantes ne changent jamais ; la fonction créée reçoit sa référence
 *   comme depuis son écran (F…, P…). Un consultant sur une Personne qui a un compte est relié par le
 *   rattachement automatique d'ADR 0056, inchangé.
 */
final class FonctionsContactEcriture
{
    public const CREABLES = ['fournisseur', 'prestataire'];

    public function __construct(
        private readonly VisibiliteContacts $visibilite,
        private readonly QualificationsPrestataire $qualifications,
        private readonly ReferentielCategories $referentiel,
    ) {}

    /** @return list<string> */
    public function fonctionsCreables(User $user): array
    {
        return array_values(array_filter(self::CREABLES, fn (string $f) => $user->can("{$f}s.create")
            && $this->visibilite->peutLire($user, $f)));
    }

    /**
     * Fonctions créables que la fiche ne porte pas encore.
     *
     * @return list<array{value: string, label: string}>
     */
    public function fonctionsAjoutables(Personne|EntrepriseTierce $fiche, User $user): array
    {
        $portees = $this->fonctionsPortees($fiche);

        return array_values(array_map(
            fn (string $f) => ['value' => $f, 'label' => PresentationContacts::LIBELLES_FONCTIONS[$f]],
            array_filter($this->fonctionsCreables($user), fn (string $f) => ! in_array($f, $portees, true)),
        ));
    }

    /**
     * @param  array{type?: ?string, qualification_ids?: ?list<string>, sous_categorie_ids?: ?list<string>, notes?: ?string}  $donnees
     */
    public function ajouter(Personne|EntrepriseTierce $fiche, string $fonction, array $donnees, User $acteur): Fournisseur|Prestataire
    {
        if ($fiche->organization_id === null || $fiche->organization_id !== $acteur->organization_id) {
            throw new AuthorizationException("Cette fiche n'appartient pas à votre organisation.");
        }
        if (! in_array($fonction, $this->fonctionsCreables($acteur), true)) {
            throw new AuthorizationException("Vous ne pouvez pas créer la fonction {$fonction}.");
        }

        return DB::transaction(function () use ($fiche, $fonction, $donnees, $acteur) {
            $fiche::whereKey($fiche->getKey())->lockForUpdate()->first();

            if (in_array($fonction, $this->fonctionsPortees($fiche), true)) {
                throw ValidationException::withMessages(['fonction' => $this->messageDejaPortee($fiche, $fonction)]);
            }

            $identite = $fiche instanceof Personne
                ? ['personne_id' => $fiche->id]
                : ['entreprise_tierce_id' => $fiche->id];
            $commun = [
                'organization_id' => $fiche->organization_id,
                ...$identite,
                'notes' => $this->texte($donnees['notes'] ?? null),
                'is_active' => true,
            ];

            if ($fonction === 'fournisseur') {
                $fournisseur = Fournisseur::create($commun);
                // Lot 8 : mêmes sous-catégories qu'à l'écran Fournisseur (lot 6a), sans effet métier.
                if (($donnees['sous_categorie_ids'] ?? []) !== []) {
                    $this->referentiel->synchroniserFournisseur($acteur, $fournisseur, $donnees['sous_categorie_ids']);
                }

                return $fournisseur;
            }

            // Lot 6b : qualifications (au moins une active) ; `type` en est la copie de compatibilité.
            $ids = $this->qualifications->idsDemandes($fiche->organization_id, $donnees['qualification_ids'] ?? null, $donnees['type'] ?? null);
            if ($ids === null) {
                throw ValidationException::withMessages(['qualification_ids' => 'Choisissez au moins une qualification du prestataire.']);
            }
            $prestataire = Prestataire::create($commun);
            $this->qualifications->synchroniser($acteur, $prestataire, $ids);

            return $prestataire->refresh();
        });
    }

    /** @return list<string> fonctions créables déjà portées (actives ou non, hors suppression logique) */
    private function fonctionsPortees(Personne|EntrepriseTierce $fiche): array
    {
        $colonne = $fiche instanceof Personne ? 'personne_id' : 'entreprise_tierce_id';

        return array_values(array_filter([
            Fournisseur::where($colonne, $fiche->id)->exists() ? 'fournisseur' : null,
            Prestataire::where($colonne, $fiche->id)->exists() ? 'prestataire' : null,
        ]));
    }

    private function messageDejaPortee(Personne|EntrepriseTierce $fiche, string $fonction): string
    {
        $colonne = $fiche instanceof Personne ? 'personne_id' : 'entreprise_tierce_id';
        $classe = $fonction === 'fournisseur' ? Fournisseur::class : Prestataire::class;
        $active = $classe::where($colonne, $fiche->id)->where('is_active', true)->exists();
        $libelle = PresentationContacts::LIBELLES_FONCTIONS[$fonction];

        return $active
            ? "Cette fiche est déjà {$libelle} : une fiche ne porte qu'une fonction {$libelle}."
            : "Cette fiche a déjà une fonction {$libelle}, désactivée : réactivez-la depuis l'écran {$libelle}s au lieu d'en créer une seconde.";
    }

    private function texte(mixed $valeur): ?string
    {
        $v = $valeur === null ? '' : trim((string) $valeur);

        return $v === '' ? null : $v;
    }
}
