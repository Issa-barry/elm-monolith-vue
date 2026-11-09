<?php

namespace Tests\Feature\Ventes;

use App\Enums\ClientType;
use App\Enums\ModeRemiseGrossiste;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionProcessus;
use App\Models\Organization;
use App\Services\Commission\CommissionEnveloppeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lot 7c du référentiel Contacts (ADR 0060, D12 à D14, CONTACT-042) : la nature du client est
 * enregistrée sur chaque nouvelle commande ; les commandes antérieures (colonne vide) gardent le
 * comportement actuel (repli sur la nature du client) ; un changement de nature ne vaut que pour
 * les nouvelles commandes ; commissions, listes et exports lisent la nature de la commande.
 */
class NatureClientCommandeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
    }

    private function client(ClientType $type): Client
    {
        return Client::factory()->create(['organization_id' => $this->org->id, 'type' => $type->value]);
    }

    private function commande(Client $client, array $attributs = []): CommandeVente
    {
        return CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'vehicule_id' => null,
            'nature_operation' => NatureOperation::VENTE_STANDARD->value,
            'statut' => StatutCommandeVente::BROUILLON,
            ...$attributs,
        ]);
    }

    public function test_la_nature_du_client_est_enregistree_a_la_creation(): void
    {
        $commande = $this->commande($this->client(ClientType::REVENDEUR));

        $this->assertSame(ClientType::REVENDEUR, $commande->fresh()->nature_client);
    }

    public function test_un_changement_de_nature_ne_vaut_que_pour_les_nouvelles_commandes(): void
    {
        $client = $this->client(ClientType::GROSSISTE);
        $ancienne = $this->commande($client);

        $client->update(['type' => ClientType::EXTERNE->value]);
        $nouvelle = $this->commande($client);

        $this->assertSame(ClientType::GROSSISTE, $ancienne->fresh()->natureClient());
        $this->assertSame(ClientType::EXTERNE, $nouvelle->fresh()->natureClient());
    }

    public function test_une_commande_anterieure_garde_le_comportement_actuel(): void
    {
        $client = $this->client(ClientType::DISTRIBUTEUR);
        $commande = $this->commande($client);
        DB::table('commandes_ventes')->where('id', $commande->id)->update(['nature_client' => null]);

        $client->update(['type' => ClientType::REVENDEUR->value]);

        $this->assertNull($commande->fresh()->nature_client);
        $this->assertSame(ClientType::REVENDEUR, $commande->fresh()->natureClient());
    }

    public function test_un_client_remplace_sur_la_commande_apporte_sa_nature(): void
    {
        $commande = $this->commande($this->client(ClientType::EXTERNE));

        $commande->update(['client_id' => $this->client(ClientType::GROSSISTE)->id]);

        $this->assertSame(ClientType::GROSSISTE, $commande->fresh()->nature_client);
    }

    public function test_les_commissions_lisent_la_nature_enregistree_sur_la_commande(): void
    {
        $client = $this->client(ClientType::GROSSISTE);
        $commande = $this->commande($client, ['mode_remise_grossiste' => ModeRemiseGrossiste::LIVRAISON->value]);
        $client->update(['type' => ClientType::EXTERNE->value]);

        // Le générateur choisit le processus d'après la nature de la commande (grossiste + livraison
        // → Transfert grossiste), même si le client est devenu externe depuis.
        CommissionEnveloppeGenerator::simulerPourCommandeVente($commande->fresh(), now());
        $this->assertTrue(CommissionProcessus::where('organization_id', $this->org->id)->where('code', CommissionProcessus::CODE_TRANSFERT_GROSSISTE)->exists());
        $this->assertFalse(CommissionProcessus::where('organization_id', $this->org->id)->where('code', CommissionProcessus::CODE_VENTE)->exists());
    }

    public function test_une_commande_anterieure_suit_toujours_la_nature_actuelle_pour_les_commissions(): void
    {
        $client = $this->client(ClientType::GROSSISTE);
        $commande = $this->commande($client, ['mode_remise_grossiste' => ModeRemiseGrossiste::LIVRAISON->value]);
        DB::table('commandes_ventes')->where('id', $commande->id)->update(['nature_client' => null]);
        $client->update(['type' => ClientType::EXTERNE->value]);

        CommissionEnveloppeGenerator::simulerPourCommandeVente($commande->fresh(), now());
        $this->assertTrue(CommissionProcessus::where('organization_id', $this->org->id)->where('code', CommissionProcessus::CODE_VENTE)->exists());
        $this->assertFalse(CommissionProcessus::where('organization_id', $this->org->id)->where('code', CommissionProcessus::CODE_TRANSFERT_GROSSISTE)->exists());
    }
}
