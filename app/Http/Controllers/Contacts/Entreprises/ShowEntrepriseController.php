<?php

namespace App\Http\Controllers\Contacts\Entreprises;

use App\Http\Controllers\Controller;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Services\Contacts\VisibiliteContacts;
use App\Support\Contacts\PresentationContacts;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fiche Entreprise du référentiel Contacts (ADR 0060, lot 2) — lecture seule : identité, fonctions
 * visibles, interlocuteurs. La gestion des interlocuteurs arrive au lot 4. Visibilité vérifiée ici
 * en plus de la policy (voir ShowPersonneController).
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
            ])
            ->values();

        return Inertia::render('Contacts/Entreprises/Show', [
            'entreprise' => $presentation->coordonneesEntreprise($entreprise),
            'fonctions' => $presentation->fonctions($fonctions),
            'interlocuteurs' => $interlocuteurs,
        ]);
    }
}
