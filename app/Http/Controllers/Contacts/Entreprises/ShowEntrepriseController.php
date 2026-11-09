<?php

namespace App\Http\Controllers\Contacts\Entreprises;

use App\Http\Controllers\Controller;
use App\Models\CategorieLibre;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Services\Contacts\FonctionsContactEcriture;
use App\Services\Contacts\QualificationsPrestataire;
use App\Services\Contacts\ReferentielCategories;
use App\Services\Contacts\VisibiliteContacts;
use App\Support\Contacts\PresentationContacts;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fiche Entreprise du référentiel Contacts (ADR 0060) : identité, fonctions visibles,
 * interlocuteurs (lot 2), fonctions ajoutables (lot 3) et droit de gérer les interlocuteurs (lot 4,
 * contrôleurs App\Http\Controllers\Contacts\Interlocuteurs). Visibilité vérifiée ici en plus de la
 * policy (voir ShowPersonneController).
 */
class ShowEntrepriseController extends Controller
{
    public function __invoke(Request $request, EntrepriseTierce $entreprise, VisibiliteContacts $visibilite): Response
    {
        $this->authorize('viewAny', EntrepriseTierce::class);

        $user = $request->user();
        abort_unless($visibilite->entrepriseVisible($entreprise, $user), 404);

        $presentation = new PresentationContacts($user->organization_id);
        $fonctions = $visibilite->fonctionsDesEntreprises(collect([$entreprise]), $user)[$entreprise->id] ?? [];

        $interlocuteurs = EntrepriseInterlocuteur::where('entreprise_tierce_id', $entreprise->id)
            ->with('personne')
            ->get()
            ->sortBy(fn (EntrepriseInterlocuteur $i) => [! $i->is_active, ! $i->est_principal, mb_strtolower((string) $i->personne?->nom_complet)])
            ->map(fn (EntrepriseInterlocuteur $i) => [
                'id' => $i->id,
                'personne_id' => $i->personne_id,
                'nom' => $i->personne?->nom_complet ?: ($i->personne?->telephone ?? 'Interlocuteur sans nom'),
                'telephone' => $i->personne?->telephone,
                'code_phone_pays' => $i->personne?->code_phone_pays,
                'email' => $i->personne?->email,
                'fonction' => $i->fonction,
                'est_principal' => $i->est_principal,
                'is_active' => $i->is_active,
                'desactive_le' => $i->desactive_le?->toDateString(),
                'notes' => $i->notes,
            ])
            ->values();

        return Inertia::render('Contacts/Entreprises/Show', [
            'entreprise' => $presentation->coordonneesEntreprise($entreprise),
            'fonctions' => $presentation->fonctions($fonctions),
            'interlocuteurs' => $interlocuteurs,
            // Lot 3 : fonctions que l'utilisateur peut ajouter et que la fiche ne porte pas encore.
            'fonctions_ajoutables' => $user->can('create', EntrepriseTierce::class) ? app(FonctionsContactEcriture::class)->fonctionsAjoutables($entreprise, $user) : [],
            // Lot 6b : qualifications Prestataire actives du référentiel (valeur = id).
            'types_prestataire' => QualificationsPrestataire::options($entreprise->organization_id),
            // Lot 8 : sous-catégories proposées à l'ajout de la fonction Fournisseur.
            'sous_categories_fournisseur' => ReferentielCategories::optionsSousCategoriesFournisseur($entreprise->organization_id),
            // Lot 4 : gestion des interlocuteurs (l'entreprise est déjà vérifiée visible ci-dessus).
            'peut_gerer_interlocuteurs' => $user->can('gererInterlocuteurs', EntrepriseTierce::class),
            // Lot 6a : catégories libres portées ; modifiables avec contacts.update (entreprise déjà vérifiée visible).
            'categories' => $portees = PresentationContacts::categoriesLibresDes('entreprise_contact_categories', 'entreprise_tierce_id', [$entreprise->id])[$entreprise->id],
            // Lot 7a : modifier l'identité de la fiche (contacts.update ; entreprise déjà vérifiée visible).
            'peut_modifier_identite' => $user->can('modifierIdentite', EntrepriseTierce::class),
            'peut_modifier_categories' => $peut = $user->can('modifierCategories', EntrepriseTierce::class),
            'categories_options' => $peut
                ? ReferentielCategories::options(CategorieLibre::where('organization_id', $entreprise->organization_id)->get(), array_column($portees, 'id'))
                : [],
        ]);
    }
}
