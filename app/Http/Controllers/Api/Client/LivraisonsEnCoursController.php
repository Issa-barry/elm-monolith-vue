<?php

namespace App\Http\Controllers\Api\Client;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutTransfert;
use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LivraisonsEnCoursController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $proprietaire = $this->findProprietaire($user);
        $livreur = $this->findLivreur($user);

        if ($proprietaire === null && $livreur === null) {
            return response()->json([]);
        }

        $vehiculeIds = $this->vehiculeIdsDuProprietaire($proprietaire);
        $equipeIds = $this->equipeIdsDuLivreur($livreur);
        $vehiculeIdsLiv = $this->vehiculeIdsDuLivreur($livreur);

        $tousVehiculeIds = $vehiculeIds->merge($vehiculeIdsLiv)->unique()->values();

        if ($tousVehiculeIds->isEmpty() && $equipeIds->isEmpty()) {
            return response()->json([]);
        }

        $transferts = TransfertLogistique::query()
            ->with([
                'siteSource:id,nom',
                'siteDestination:id,nom',
                'vehicule:id,nom_vehicule,immatriculation',
                'equipeLivraison:id,nom',
                'lignes',
            ])
            ->where('statut', StatutTransfert::TRANSIT->value)
            ->when($user->organization_id, fn (Builder $q) => $q->where('organization_id', $user->organization_id))
            ->where(fn (Builder $q) => $q
                ->when($vehiculeIds->isNotEmpty(), fn (Builder $q2) => $q2->orWhereIn('vehicule_id', $vehiculeIds))
                ->when($equipeIds->isNotEmpty(), fn (Builder $q2) => $q2->orWhereIn('equipe_livraison_id', $equipeIds))
            )
            ->orderByDesc('date_depart_reelle')
            ->get()
            ->map(fn ($t) => $this->formatTransfert($t));

        $commandes = collect();
        if ($tousVehiculeIds->isNotEmpty()) {
            $commandes = CommandeVente::query()
                ->with(['site:id,nom', 'vehicule:id,nom_vehicule,immatriculation', 'vehicule.equipe:id,nom', 'client:id,nom,prenom', 'lignes:id,commande_vente_id,quantite_demandee'])
                ->where('statut', StatutCommandeVente::LIVRAISON_EN_COURS->value)
                ->when($user->organization_id, fn (Builder $q) => $q->where('organization_id', $user->organization_id))
                ->whereIn('vehicule_id', $tousVehiculeIds)
                ->orderByDesc('validated_at')
                ->get()
                ->map(fn ($c) => $this->formatCommande($c));
        }

        return response()->json($transferts->toBase()->merge($commandes)->values());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function findProprietaire(User $user): ?Proprietaire
    {
        return Proprietaire::query()
            ->when($user->organization_id, fn (Builder $q) => $q->where('organization_id', $user->organization_id))
            ->where(fn (Builder $q) => $q->where('user_id', $user->id)
                ->when($user->telephone, fn (Builder $q2) => $q2->orWhere('telephone', $user->telephone)))
            ->first();
    }

    private function findLivreur(User $user): ?Livreur
    {
        return Livreur::query()
            ->when($user->organization_id, fn (Builder $q) => $q->where('organization_id', $user->organization_id))
            ->where(fn (Builder $q) => $q->where('user_id', $user->id)
                ->when($user->telephone, fn (Builder $q2) => $q2->orWhere('telephone', $user->telephone)))
            ->first();
    }

    private function vehiculeIdsDuProprietaire(?Proprietaire $proprietaire): Collection
    {
        if ($proprietaire === null) {
            return collect();
        }

        return Vehicule::where('proprietaire_id', $proprietaire->id)->pluck('id');
    }

    private function vehiculeIdsDuLivreur(?Livreur $livreur): Collection
    {
        if ($livreur === null) {
            return collect();
        }

        $equipeIds = $livreur->equipes()->pluck('equipes_livraison.id');

        return Vehicule::whereHas('equipe', fn ($q) => $q->whereIn('id', $equipeIds))->pluck('id');
    }

    private function equipeIdsDuLivreur(?Livreur $livreur): Collection
    {
        if ($livreur === null) {
            return collect();
        }

        return $livreur->equipes()->pluck('equipes_livraison.id');
    }

    private function formatCommande(CommandeVente $c): array
    {
        $client = $c->client;
        $clientNom = $client
            ? trim(($client->prenom ?? '').' '.($client->nom ?? ''))
            : 'Vente directe';

        return [
            'id' => $c->id,
            'reference' => $c->reference ?? '—',
            'statut' => 'commande',
            'statut_label' => 'Commande en cours',
            'site_source' => $c->site?->nom ?? '—',
            'site_destination' => $clientNom,
            'vehicule' => $c->vehicule ? [
                'nom' => $c->vehicule->nom_vehicule,
                'immatriculation' => $c->vehicule->immatriculation,
            ] : null,
            'equipe_nom' => $c->vehicule?->equipe?->nom ?? '—',
            'date_depart' => $c->validated_at?->toDateString(),
            'date_arrivee_prevue' => null,
            'nb_packs' => (int) $c->lignes->sum('quantite_demandee'),
        ];
    }

    private function formatTransfert(TransfertLogistique $t): array
    {
        return [
            'id' => $t->id,
            'reference' => $t->reference,
            'statut' => $t->statut instanceof \BackedEnum ? $t->statut->value : $t->statut,
            'statut_label' => 'Livraison en cours',
            'site_source' => $t->siteSource?->nom ?? '—',
            'site_destination' => $t->siteDestination?->nom ?? '—',
            'vehicule' => $t->vehicule ? [
                'nom' => $t->vehicule->nom_vehicule,
                'immatriculation' => $t->vehicule->immatriculation,
            ] : null,
            'equipe_nom' => $t->equipeLivraison?->nom ?? '—',
            'date_depart' => $t->date_depart_reelle?->toDateString(),
            'date_arrivee_prevue' => $t->date_arrivee_prevue?->toDateString(),
            'nb_packs' => (int) $t->lignes->sum('quantite_chargee'),
        ];
    }
}
