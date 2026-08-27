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
use App\Services\Client\ClientIdentityResolver;
use App\Services\Client\Data\LivraisonEnCoursRow;
use App\Services\Client\Data\LivraisonEnCoursVehicule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LivraisonsEnCoursController extends Controller
{
    public function __invoke(Request $request, ClientIdentityResolver $identityResolver): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $identity = $identityResolver->resolve($user);
        $proprietaire = $identity->proprietaire;
        $livreur = $identity->livreur;

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

        $organizationId = $identity->organizationId;

        $transferts = TransfertLogistique::query()
            ->with([
                'siteSource:id,nom',
                'siteDestination:id,nom',
                'vehicule:id,nom_vehicule,immatriculation',
                'equipeLivraison:id,vehicule_id',
                'equipeLivraison.vehicule:id,nom_vehicule',
                'lignes',
            ])
            ->where('statut', StatutTransfert::TRANSIT->value)
            ->when($organizationId, fn (Builder $q) => $q->where('organization_id', $organizationId))
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
                ->with(['site:id,nom', 'vehicule:id,nom_vehicule,immatriculation', 'vehicule.equipe:id,vehicule_id', 'client:id,nom,prenom', 'lignes:id,commande_vente_id,quantite_demandee'])
                ->where('statut', StatutCommandeVente::LIVRAISON_EN_COURS->value)
                ->when($organizationId, fn (Builder $q) => $q->where('organization_id', $organizationId))
                ->whereIn('vehicule_id', $tousVehiculeIds)
                ->orderByDesc('validated_at')
                ->get()
                ->map(fn ($c) => $this->formatCommande($c));
        }

        return response()->json($transferts->toBase()->merge($commandes)->values());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

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

    private function formatCommande(CommandeVente $c): LivraisonEnCoursRow
    {
        $client = $c->client;
        $clientNom = $client?->nom_complet ?? 'Vente directe';

        return new LivraisonEnCoursRow(
            id: $c->id,
            reference: $c->reference ?? '—',
            statut: 'commande',
            statutLabel: 'Commande en cours',
            siteSource: $c->site?->nom ?? '—',
            siteDestination: $clientNom,
            vehicule: $c->vehicule ? new LivraisonEnCoursVehicule(
                nom: $c->vehicule->nom_vehicule,
                immatriculation: $c->vehicule->immatriculation,
            ) : null,
            equipeNom: $c->vehicule?->nom_vehicule ?? '—',
            dateDepart: $c->validated_at?->toDateString(),
            dateArriveePrevue: null,
            nbPacks: (int) $c->lignes->sum('quantite_demandee'),
        );
    }

    private function formatTransfert(TransfertLogistique $t): LivraisonEnCoursRow
    {
        return new LivraisonEnCoursRow(
            id: $t->id,
            reference: $t->reference,
            statut: $t->statut instanceof \BackedEnum ? $t->statut->value : $t->statut,
            statutLabel: 'Livraison en cours',
            siteSource: $t->siteSource?->nom ?? '—',
            siteDestination: $t->siteDestination?->nom ?? '—',
            vehicule: $t->vehicule ? new LivraisonEnCoursVehicule(
                nom: $t->vehicule->nom_vehicule,
                immatriculation: $t->vehicule->immatriculation,
            ) : null,
            equipeNom: $t->equipeLivraison?->nom ?? '—',
            dateDepart: $t->date_depart_reelle?->toDateString(),
            dateArriveePrevue: $t->date_arrivee_prevue?->toDateString(),
            nbPacks: (int) $t->lignes->sum('quantite_chargee'),
        );
    }
}
