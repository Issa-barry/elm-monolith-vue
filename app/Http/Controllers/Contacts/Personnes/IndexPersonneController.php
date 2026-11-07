<?php

namespace App\Http\Controllers\Contacts\Personnes;

use App\Http\Controllers\Controller;
use App\Models\Personne;
use App\Services\Contacts\VisibiliteContacts;
use App\Support\Contacts\PresentationContacts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Liste « Personnes physiques » du référentiel Contacts (ADR 0060, lot 2) — lecture seule. Ne
 * contient que les personnes dont au moins une fonction est visible du lecteur (CONTACT-014) ;
 * les filtres ne proposent que les fonctions lisibles (CONTACT-013).
 */
class IndexPersonneController extends Controller
{
    public function __invoke(Request $request, VisibiliteContacts $visibilite): Response
    {
        $this->authorize('viewAny', Personne::class);

        $user = $request->user();
        $lisibles = $visibilite->fonctionsLisibles($user);
        $presentation = new PresentationContacts($user->organization_id);
        $options = $presentation->optionsFiltres($lisibles);

        $filters = $request->validate([
            'recherche' => ['nullable', 'string', 'max:100'],
            'fonction' => ['nullable', Rule::in($lisibles)],
            'sous_categorie' => ['nullable', Rule::in(array_column($options['sous_categories'], 'value'))],
            'ville' => ['nullable', 'string', 'max:100'],
        ]);

        $requete = $visibilite->personnesVisibles($user)
            ->when($filters['fonction'] ?? null, fn (Builder $q, string $f) => $visibilite->personnesAvecFonction($q, $user, $f))
            ->when($filters['sous_categorie'] ?? null, function (Builder $q, string $valeur) use ($visibilite, $user) {
                [$categorie, $code] = explode(':', $valeur, 2);

                return $visibilite->personnesAvecFonction($q, $user, $categorie, $code);
            })
            ->when(trim($filters['recherche'] ?? ''), fn (Builder $q, string $terme) => $visibilite->rechercherPersonnes($q, $terme, $user))
            ->when(trim($filters['ville'] ?? ''), fn (Builder $q, string $ville) => $q->where('personnes.ville', 'like', '%'.$ville.'%'));

        $page = $visibilite->trierPersonnes($requete)->paginate(25)->withQueryString();
        $fonctions = $visibilite->fonctionsDesPersonnes(collect($page->items()), $user);

        $page->through(function (Personne $p) use ($fonctions, $presentation) {
            $siennes = $fonctions[$p->id] ?? [];

            return [
                'id' => $p->id,
                'nom' => $presentation->nomPersonne($p, $siennes),
                'telephone' => $p->telephone,
                'code_phone_pays' => $p->code_phone_pays,
                'ville' => $p->ville,
                'quartier' => $p->quartier,
                'fonctions' => $presentation->fonctions($siennes),
            ];
        });

        return Inertia::render('Contacts/Personnes/Index', [
            'personnes' => $page,
            'filters' => $filters,
            'options' => $options,
        ]);
    }
}
