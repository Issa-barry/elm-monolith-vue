<?php

namespace App\Services\Search\Providers;

use App\Models\EntrepriseTierce;
use App\Models\Personne;
use App\Models\User;
use App\Services\Contacts\VisibiliteContacts;
use App\Services\Search\SearchProvider;
use App\Services\Search\SearchResultItem;
use App\Support\Contacts\PresentationContacts;
use Illuminate\Support\Collection;

/**
 * Groupe « Contacts » de la recherche globale (ADR 0060, lot 2) : fiches Personne et Entreprise,
 * avec la même visibilité que les listes (VisibiliteContacts) — une personne sans fonction visible
 * n'apparaît jamais. Les groupes existants (Clients, Propriétaires, Livreurs...) ne changent pas :
 * une même personne peut figurer dans deux groupes jusqu'au lot 8.
 *
 * L'id d'un résultat porte le type de fiche (« personnes/{id} », « entreprises/{id} ») pour que le
 * frontend construise le lien sans connaître le type à part.
 */
class ContactSearchProvider implements SearchProvider
{
    public function key(): string
    {
        return 'contacts';
    }

    public function label(): string
    {
        return 'Contacts';
    }

    public function authorize(User $user): bool
    {
        return $user->can('contacts.read');
    }

    public function search(string $query, User $user, int $limit): Collection
    {
        $visibilite = app(VisibiliteContacts::class);
        $presentation = new PresentationContacts($user->organization_id);

        $personnes = $visibilite->trierPersonnes($visibilite->rechercherPersonnes($visibilite->personnesVisibles($user), $query, $user))
            ->limit($limit)
            ->get();
        $fonctions = $visibilite->fonctionsDesPersonnes($personnes, $user);

        $resultats = $personnes->map(fn (Personne $p) => new SearchResultItem(
            id: 'personnes/'.$p->id,
            title: $presentation->nomPersonne($p, $fonctions[$p->id] ?? []),
            subtitle: collect([$p->telephone, $this->libelles($fonctions[$p->id] ?? [])])->filter()->implode(' · '),
        ));

        $restant = $limit - $resultats->count();
        if ($restant > 0) {
            $entreprises = $visibilite->rechercherEntreprises($visibilite->entreprisesVisibles($user), $query)
                ->orderBy('raison_sociale')
                ->limit($restant)
                ->get();

            $resultats = $resultats->concat($entreprises->map(fn (EntrepriseTierce $e) => new SearchResultItem(
                id: 'entreprises/'.$e->id,
                title: $e->raison_sociale,
                subtitle: collect([$e->telephone, 'Entreprise'])->filter()->implode(' · '),
            )));
        }

        return $resultats->values();
    }

    private function libelles(array $fonctions): string
    {
        return collect($fonctions)->pluck('fonction')->unique()
            ->map(fn (string $f) => PresentationContacts::LIBELLES_FONCTIONS[$f] ?? $f)
            ->implode(', ');
    }
}
