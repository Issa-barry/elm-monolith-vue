<?php

namespace App\Http\Controllers;

use App\Http\Requests\Produits\UpdateCategorieTarifsGrossisteRequest;
use App\Models\CategorieTarifGrossiste;
use App\Models\Client;
use App\Models\ProduitVariante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tarifs Grossiste (catégorie × mode Enlèvement/Livraison) — PROPRES À CHAQUE CLIENT (décision
 * produit du 05/09/2026, révisant un premier jet organisation-wide) : gérés depuis la fiche du
 * client concerné, jamais une page d'administration globale. Chaque sauvegarde remplace
 * l'ensemble des tarifs soumis pour CE client (upsert par client+catégorie+mode). Consommés par
 * GrossisteTarifResolver au moment de la vente. Cf. docs/grossiste.md.
 */
class CategorieTarifGrossisteController extends Controller
{
    /**
     * Grille du client — fetch live depuis Ventes/Create.vue/Edit.vue au choix d'un client
     * Grossiste (le mode/la catégorie ne sont connus qu'à ce moment-là). Gatée par la permission
     * de vente (créer une commande implique de voir le tarif applicable), pas par une permission
     * d'administration séparée.
     */
    public function forClient(Client $client): JsonResponse
    {
        $user = auth()->user();
        abort_unless($user->can('ventes.create') || $user->can('ventes.update'), 403);
        abort_unless($client->organization_id === $user->organization_id, 403);

        return response()->json(
            CategorieTarifGrossiste::gridForClient($client->organization_id, $client->id)
        );
    }

    /**
     * Remplace INTÉGRALEMENT l'ensemble des tarifs de ce client par ceux soumis — jamais un ajout
     * incrémental muet : une ligne retirée côté UI (bouton "Ajouter une ligne"/suppression, cf.
     * Clients/Show.vue) doit réellement supprimer le tarif correspondant, pas seulement cesser de
     * l'afficher. `tarifs` peut être vide (tous les tarifs de ce client sont alors supprimés).
     */
    public function update(UpdateCategorieTarifsGrossisteRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = $request->validated();

        foreach ($data['tarifs'] as $ligne) {
            $this->assertTarifCouvreCout($client->organization_id, $ligne['categorie_id'], (int) $ligne['prix']);
        }

        DB::transaction(function () use ($client, $data) {
            $clesSoumises = collect($data['tarifs'])
                ->map(fn (array $t) => "{$t['categorie_id']}:{$t['mode']}");

            CategorieTarifGrossiste::where('client_id', $client->id)
                ->get()
                ->reject(fn (CategorieTarifGrossiste $t) => $clesSoumises->contains("{$t->categorie_id}:{$t->mode->value}"))
                ->each(fn (CategorieTarifGrossiste $t) => $t->delete());

            foreach ($data['tarifs'] as $ligne) {
                CategorieTarifGrossiste::updateOrCreate(
                    [
                        'client_id' => $client->id,
                        'categorie_id' => $ligne['categorie_id'],
                        'mode' => $ligne['mode'],
                    ],
                    ['organization_id' => $client->organization_id, 'prix' => $ligne['prix']],
                );
            }
        });

        return back()->with('success', 'Tarifs Grossiste mis à jour.');
    }

    /**
     * Garde-fou anti-vente-à-perte au moment de l'administration du tarif — même principe que
     * GrossisteTarifResolver::validerCoherenceMarge(), appliqué ici à TOUS les produits déjà
     * rattachés à la catégorie (pas seulement celui d'une ligne de commande). Défense en
     * profondeur : le resolver revalide de toute façon par variante au moment de la vente.
     */
    private function assertTarifCouvreCout(string $orgId, string $categorieId, int $prix): void
    {
        $variantes = ProduitVariante::query()
            ->whereHas('produit', fn ($q) => $q->where('organization_id', $orgId)->where('categorie_id', $categorieId))
            ->with('produit.produitType')
            ->get();

        foreach ($variantes as $variante) {
            $champ = $variante->produit?->produitType?->champPrixReference();
            if (! $champ) {
                continue;
            }

            $reference = (int) ($variante->{$champ} ?? 0);

            if ($reference > 0 && $prix <= $reference) {
                throw ValidationException::withMessages([
                    'tarifs' => "Le tarif proposé ({$prix} GNF) ne couvre pas le coût de référence du produit « {$variante->produit?->nom} » ({$reference} GNF) de cette catégorie.",
                ]);
            }
        }
    }
}
