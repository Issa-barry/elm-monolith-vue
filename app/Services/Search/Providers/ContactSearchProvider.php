<?php

namespace App\Services\Search\Providers;

use App\Models\Client;
use App\Models\EntrepriseTierce;
use App\Models\Personne;
use App\Models\User;
use App\Services\Contacts\VisibiliteContacts;
use App\Services\Search\Concerns\EscapesSearchTerm;
use App\Services\Search\SearchProvider;
use App\Services\Search\SearchResultItem;
use App\Support\Contacts\PresentationContacts;
use Illuminate\Support\Collection;

/**
 * Groupe « Contacts » de la recherche globale (ADR 0060) : fiches Personne et Entreprise, avec la
 * même visibilité que les listes (VisibiliteContacts) — une fiche sans fonction visible n'apparaît
 * jamais. Chaque résultat porte un badge par fonction visible, avec le lien vers son écran métier.
 *
 * Lot 8 : au back-office, ce groupe remplace les groupes Clients, Propriétaires et Livreurs
 * (GlobalSearchService::GROUPES_CONTACTS) ; il retrouve donc aussi un client par le nom et le
 * téléphone de sa propre fiche, et un client encore sans Personne (anomalie de reprise).
 *
 * L'id d'un résultat porte le type de fiche (« personnes/{id} », « entreprises/{id} »).
 */
class ContactSearchProvider implements SearchProvider
{
    use EscapesSearchTerm;

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
            subtitle: $p->telephone,
            href: "/backoffice/contacts/personnes/{$p->id}",
            badges: $this->badges($presentation, $fonctions[$p->id] ?? []),
        ));

        $restant = $limit - $resultats->count();
        if ($restant > 0) {
            $entreprises = $visibilite->rechercherEntreprises($visibilite->entreprisesVisibles($user), $query)
                ->orderBy('raison_sociale')
                ->limit($restant)
                ->get();
            $fonctionsEntreprises = $visibilite->fonctionsDesEntreprises($entreprises, $user);

            $resultats = $resultats->concat($entreprises->map(fn (EntrepriseTierce $e) => new SearchResultItem(
                id: 'entreprises/'.$e->id,
                title: $e->raison_sociale,
                subtitle: collect([$e->telephone, 'Entreprise'])->filter()->implode(' · '),
                href: "/backoffice/contacts/entreprises/{$e->id}",
                badges: $this->badges($presentation, $fonctionsEntreprises[$e->id] ?? []),
            )));
        }

        $restant = $limit - $resultats->count();
        if ($restant > 0 && $visibilite->peutLire($user, 'client')) {
            $resultats = $resultats->concat($this->clientsSansPersonne($query, $user, $restant));
        }

        return $resultats->values();
    }

    /**
     * Client encore sans Personne (anomalie signalée par `contacts:ecarts-clients`) : sans fiche
     * Contacts, il reste retrouvable comme dans l'ancien groupe Clients.
     *
     * @return Collection<int, SearchResultItem>
     */
    private function clientsSansPersonne(string $query, User $user, int $limit): Collection
    {
        $like = $this->likeTerm($query);

        return Client::query()
            ->where('organization_id', $user->organization_id)
            ->whereNull('personne_id')
            ->where(fn ($q) => $q->where('nom_complet', 'like', $like)
                ->orWhere('nom', 'like', $like)
                ->orWhere('prenom', 'like', $like)
                ->orWhere('telephone', 'like', $like))
            ->orderBy('nom_complet')
            ->limit($limit)
            ->get(['id', 'nom', 'prenom', 'nom_complet', 'telephone'])
            ->map(fn (Client $c) => new SearchResultItem(
                id: 'clients/'.$c->id,
                title: $c->nom_complet,
                subtitle: $c->telephone,
                href: "/backoffice/clients/{$c->id}",
                badges: [['libelle' => PresentationContacts::LIBELLES_FONCTIONS['client'], 'href' => "/backoffice/clients/{$c->id}"]],
            ));
    }

    /**
     * Un badge par fonction visible (doublons de libellé fusionnés), avec le lien vers l'écran métier
     * quand il existe.
     *
     * @return list<array{libelle: string, href: ?string}>
     */
    private function badges(PresentationContacts $presentation, array $fonctions): array
    {
        return collect($presentation->fonctions($fonctions))
            ->map(fn (array $f) => ['libelle' => $f['libelle'], 'href' => $f['lien']])
            ->unique(fn (array $b) => $b['libelle'].'|'.$b['href'])
            ->values()
            ->all();
    }
}
