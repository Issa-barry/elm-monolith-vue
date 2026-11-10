<?php

namespace App\Http\Controllers\Contacts\Entreprises;

use App\Http\Controllers\Controller;
use App\Models\EntrepriseTierce;
use App\Services\Contacts\FonctionsContactEcriture;
use App\Services\Contacts\VisibiliteContacts;
use App\Support\Contacts\PresentationContacts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Liste « Entreprises » du référentiel Contacts (ADR 0060, lot 2) — lecture seule. Ne contient que
 * les entreprises dont au moins une fonction est visible du lecteur (CONTACT-014).
 */
class IndexEntrepriseController extends Controller
{
    public function __invoke(Request $request, VisibiliteContacts $visibilite): Response
    {
        $this->authorize('viewAny', EntrepriseTierce::class);

        $user = $request->user();
        $lisibles = array_values(array_intersect(VisibiliteContacts::FONCTIONS_ENTREPRISE, $visibilite->fonctionsLisibles($user)));
        $presentation = new PresentationContacts($user->organization_id);
        $options = $presentation->optionsFiltres($lisibles);
        $options['sous_categories'] = array_values(array_filter(
            $options['sous_categories'],
            fn (array $o) => in_array(explode(':', $o['value'])[0], $lisibles, true),
        ));

        $filters = $request->validate([
            'recherche' => ['nullable', 'string', 'max:100'],
            'statut' => ['nullable', Rule::in(['actif', 'inactif'])],
            'fonction' => ['nullable', Rule::in($lisibles)],
            'sous_categorie' => ['nullable', Rule::in(array_column($options['sous_categories'], 'value'))],
            'ville' => ['nullable', 'string', 'max:100'],
            'categorie' => ['nullable', Rule::in(array_column($options['categories'], 'value'))],
        ]);

        $page = $visibilite->entreprisesVisibles($user)
            ->with(['interlocuteurPrincipal.personne'])
            // Lot 6a : catégorie libre (étiquette sans effet). Ne fait que réduire les fiches visibles.
            ->when($filters['categorie'] ?? null, fn (Builder $q, string $code) => $q->whereExists(fn (QueryBuilder $s) => $s->from('entreprise_contact_categories')
                ->join('contact_categories', 'contact_categories.id', '=', 'entreprise_contact_categories.contact_categorie_id')
                ->whereColumn('entreprise_contact_categories.entreprise_tierce_id', 'entreprises_tierces.id')
                ->where('contact_categories.organization_id', $user->organization_id)
                ->where('contact_categories.code', $code)))
            ->when($filters['statut'] ?? null, fn (Builder $q, string $s) => $q->where('entreprises_tierces.is_active', $s === 'actif'))
            ->when($filters['fonction'] ?? null, fn (Builder $q, string $f) => $visibilite->entreprisesAvecFonction($q, $user, $f))
            ->when($filters['sous_categorie'] ?? null, function (Builder $q, string $valeur) use ($visibilite, $user) {
                [$categorie, $code] = explode(':', $valeur, 2);

                return $visibilite->entreprisesAvecFonction($q, $user, $categorie, $code);
            })
            ->when(trim($filters['recherche'] ?? ''), fn (Builder $q, string $terme) => $visibilite->rechercherEntreprises($q, $terme))
            ->when(trim($filters['ville'] ?? ''), fn (Builder $q, string $ville) => $q->where('entreprises_tierces.ville', 'like', '%'.$ville.'%'))
            ->orderBy('entreprises_tierces.raison_sociale')
            ->orderBy('entreprises_tierces.id')
            ->paginate(25)
            ->withQueryString();

        $fonctions = $visibilite->fonctionsDesEntreprises(collect($page->items()), $user);

        $page->through(fn (EntrepriseTierce $e) => [
            'id' => $e->id,
            'raison_sociale' => $e->raison_sociale,
            'nom_commercial' => $e->nom_commercial,
            'telephone' => $e->telephone,
            'code_phone_pays' => $e->code_phone_pays,
            'ville' => $e->ville,
            'is_active' => $e->is_active,
            'interlocuteur_principal' => $e->interlocuteurPrincipal?->personne?->nom_complet ?: null,
            'fonctions' => $presentation->fonctions($fonctions[$e->id] ?? []),
        ]);

        return Inertia::render('Contacts/Entreprises/Index', [
            'entreprises' => $page,
            'filters' => $filters,
            'options' => $options,
            'peut_creer' => $user->can('create', EntrepriseTierce::class) && app(FonctionsContactEcriture::class)->fonctionsCreables($user) !== [],
        ]);
    }
}
