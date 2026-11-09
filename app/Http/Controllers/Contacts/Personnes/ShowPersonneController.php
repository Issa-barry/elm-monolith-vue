<?php

namespace App\Http\Controllers\Contacts\Personnes;

use App\Http\Controllers\Controller;
use App\Models\CategorieLibre;
use App\Models\EntrepriseInterlocuteur;
use App\Models\Personne;
use App\Services\Contacts\FonctionsContactEcriture;
use App\Services\Contacts\QualificationsPrestataire;
use App\Services\Contacts\ReferentielCategories;
use App\Services\Contacts\VisibiliteContacts;
use App\Support\Contacts\PresentationContacts;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fiche Personne du référentiel Contacts (ADR 0060, lot 2) — lecture seule. La visibilité est
 * vérifiée ici en plus de la policy : Gate::before accorde tout au super_admin, mais une fiche
 * d'une autre organisation ou sans fonction visible reste introuvable (404).
 */
class ShowPersonneController extends Controller
{
    public function __invoke(Request $request, Personne $personne, VisibiliteContacts $visibilite): Response
    {
        $this->authorize('viewAny', Personne::class);

        $user = $request->user();
        abort_unless($visibilite->personneVisible($personne, $user), 404);

        $presentation = new PresentationContacts($user->organization_id);
        $fonctions = $visibilite->fonctionsDesPersonnes(collect([$personne]), $user)[$personne->id] ?? [];

        $liens = collect($fonctions)->where('fonction', 'interlocuteur')->pluck('id')->all();
        $entreprises = EntrepriseInterlocuteur::whereIn('id', $liens)
            ->with('entrepriseTierce')
            ->get()
            ->sortBy(fn (EntrepriseInterlocuteur $i) => mb_strtolower((string) $i->entrepriseTierce?->raison_sociale))
            ->map(fn (EntrepriseInterlocuteur $i) => [
                'id' => $i->id,
                'entreprise_id' => $i->entreprise_tierce_id,
                'raison_sociale' => $i->entrepriseTierce?->raison_sociale,
                'fonction' => $i->fonction,
                'est_principal' => $i->est_principal,
                'is_active' => $i->is_active,
            ])
            ->values();

        return Inertia::render('Contacts/Personnes/Show', [
            'personne' => [
                ...$presentation->coordonneesPersonne($personne),
                'nom_affiche' => $presentation->nomPersonne($personne, $fonctions),
            ],
            'fonctions' => $presentation->fonctions(array_values(array_filter($fonctions, fn (array $f) => $f['fonction'] !== 'interlocuteur'))),
            'entreprises' => $entreprises,
            // Lot 3 : fonctions que l'utilisateur peut ajouter et que la fiche ne porte pas encore.
            'fonctions_ajoutables' => $user->can('create', Personne::class) ? app(FonctionsContactEcriture::class)->fonctionsAjoutables($personne, $user) : [],
            // Lot 6b : qualifications Prestataire actives du référentiel (valeur = id).
            'types_prestataire' => QualificationsPrestataire::options($personne->organization_id),
            // Lot 8 : sous-catégories proposées à l'ajout de la fonction Fournisseur.
            'sous_categories_fournisseur' => ReferentielCategories::optionsSousCategoriesFournisseur($personne->organization_id),
            // Lot 6a : catégories libres portées ; modifiables avec contacts.update (fiche déjà vérifiée visible).
            'categories' => $portees = PresentationContacts::categoriesLibresDes('personne_contact_categories', 'personne_id', [$personne->id])[$personne->id],
            // Lot 7a : modifier l'identité de la fiche (contacts.update ; fiche déjà vérifiée visible).
            'peut_modifier_identite' => $user->can('modifierIdentite', Personne::class),
            'peut_modifier_categories' => $peut = $user->can('modifierCategories', Personne::class),
            'categories_options' => $peut
                ? ReferentielCategories::options(CategorieLibre::where('organization_id', $personne->organization_id)->get(), array_column($portees, 'id'))
                : [],
        ]);
    }
}
