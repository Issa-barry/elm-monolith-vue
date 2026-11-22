<?php

namespace Tests\Feature\Concerns;

use App\Models\Site;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Jeu commun des tests de listes paginées côté serveur (Ventes, Factures) : une organisation,
 * deux agences, une variante de produit, et un vendeur limité à l'agence A pour vérifier le
 * périmètre d'agence. À utiliser avec HasOrgAndUser et HasProduitVariante.
 */
trait HasListePagineeDeuxAgences
{
    private Site $agenceA;

    private Site $agenceB;

    private string $varianteId;

    private int $sequence = 0;

    private function preparerDeuxAgences(): void
    {
        $this->initOrgAndUser(['ventes.read']);
        $this->agenceA = Site::where('organization_id', $this->org->id)->firstOrFail();
        $this->agenceB = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence B', 'type' => 'depot', 'localisation' => 'Kindia']);
        $this->varianteId = $this->makeProduitAvecVariante($this->org)->variantes()->first()->id;
    }

    /** Vendeur rattaché à la seule agence A. */
    private function vendeurAgenceA(): User
    {
        Role::firstOrCreate(['name' => 'vendeur_test', 'guard_name' => 'web']);
        $vendeur = User::factory()->create(['organization_id' => $this->org->id]);
        $vendeur->assignRole('vendeur_test');
        $vendeur->givePermissionTo('ventes.read');
        $vendeur->sites()->attach($this->agenceA->id, ['role' => 'employe', 'is_default' => true]);

        return $vendeur;
    }

    /** @return array<string, mixed> props Inertia de la liste */
    private function props(User $user, string $route, array $params = []): array
    {
        return $this->actingAs($user)->get(route($route, $params))->assertOk()->viewData('page')['props'];
    }
}
