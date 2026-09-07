<?php

namespace Tests\Feature;

use App\Contracts\SmsGateway;
use App\Enums\ClientType;
use App\Enums\CommunicationEvent;
use App\Enums\CommunicationModule;
use App\Enums\CommunicationRecipientType;
use App\Enums\MessageChannel;
use App\Enums\StatutCommandeVente;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommunicationRule;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\MessageLog;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Bout-en-bout — VRAIES routes HTTP (cf. rapport notifications de commande,
 * 07/09/2026, point 13 : audit des points de déclenchement confirmé contre le
 * frontend réel avant d'écrire ce test) :
 *
 * - "Confirmer" (Ventes/Show.vue::confirmer()) → PATCH /ventes/{id}/valider
 *   → CommandeVenteController::valider() → NotifierLivreursCommandeVenteJob.
 * - "Valider le chargement" (Ventes/partials/ChargementDialog.vue) → POST
 *   /ventes/{id}/statut/avancer (même URL que "démarrer chargement", le
 *   contrôleur distingue via l'ancien statut) → CommandeVenteStatutController::
 *   avancer() → NotifierChargementValideCommandeVenteJob.
 */
class CommandeVenteCommunicationTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    private Categorie $categorie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update', 'ventes.confirmer']);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Défaut',
            'statut' => 'actif',
        ]);
    }

    private function fakeSms(): SmsGateway
    {
        return new class implements SmsGateway
        {
            public array $sentTo = [];

            public bool $shouldThrow = false;

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(string $phoneNumber, string $message): ?string
            {
                if ($this->shouldThrow) {
                    throw new \RuntimeException('Nimba : panne simulée.', 500);
                }

                $this->sentTo[] = $phoneNumber;

                return 'fake-id';
            }
        };
    }

    private function enableRule(CommunicationModule $module, CommunicationEvent $event, CommunicationRecipientType $recipient, ?ClientType $clientType = null): void
    {
        CommunicationRule::create([
            'organization_id' => $this->org->id,
            'module' => $module,
            'event' => $event,
            'recipient_type' => $recipient,
            'client_type' => $clientType,
            'channel' => MessageChannel::SMS,
            'enabled' => true,
        ]);
    }

    /** @return array{commande: CommandeVente, livreur: Livreur} */
    private function makeCommandeAvecEquipe(?Client $client = null): array
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 10,
        ]);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224620005000']);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Equipe Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 1]);

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit Test', 'categorie_id' => $this->categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
        $this->seedVarianteStockSuffisant($produit->variantePrincipale()->first(), $this->site);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $vehicule->id,
            'client_id' => $client?->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);
        $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => 1500.0,
            'prix_vente_snapshot' => 2000.0,
            'total_ligne' => 4000.0,
        ]);

        return ['commande' => $commande, 'livreur' => $livreur];
    }

    public function test_confirming_via_the_real_route_sends_an_sms_to_the_livreur(): void
    {
        $gateway = $this->fakeSms();
        $this->app->instance(SmsGateway::class, $gateway);
        $this->enableRule(CommunicationModule::VENTES, CommunicationEvent::COMMANDE_CONFIRMEE, CommunicationRecipientType::LIVREUR);

        ['commande' => $commande, 'livreur' => $livreur] = $this->makeCommandeAvecEquipe();

        $this->actingAs($this->user)
            ->patch("/backoffice/ventes/{$commande->id}/valider")
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::A_CHARGER, $commande->fresh()->statut);
        $this->assertSame([$livreur->telephone], $gateway->sentTo);
    }

    public function test_validating_chargement_via_the_real_route_notifies_livreur_and_client(): void
    {
        $gateway = $this->fakeSms();
        $this->app->instance(SmsGateway::class, $gateway);
        $this->enableRule(CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE, CommunicationRecipientType::LIVREUR);
        $this->enableRule(CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE, CommunicationRecipientType::CLIENT, ClientType::EXTERNE);

        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => ClientType::EXTERNE, 'telephone' => '+224620006000']);
        ['commande' => $commande, 'livreur' => $livreur] = $this->makeCommandeAvecEquipe($client);

        $this->actingAs($this->user);
        $this->patch("/backoffice/ventes/{$commande->id}/valider")->assertRedirect();
        // "Démarrer le chargement" — même endpoint, A_CHARGER → CHARGEMENT_EN_COURS,
        // aucune règle configurée ici : ne doit rien envoyer.
        $this->post("/backoffice/ventes/{$commande->id}/statut/avancer")->assertRedirect();
        $this->assertEmpty($gateway->sentTo);

        // "Valider le chargement" — CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS, le
        // VRAI déclencheur de ce chantier.
        $ligne = $commande->fresh()->lignes()->first();
        $this->post("/backoffice/ventes/{$commande->id}/statut/avancer", [
            'lignes' => [[
                'id' => $ligne->id,
                'quantite_chargee' => $ligne->quantite_demandee,
                'type_ecart' => 'conforme',
            ]],
        ])->assertRedirect();

        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);
        $this->assertCount(2, $gateway->sentTo);
        $this->assertContains($livreur->telephone, $gateway->sentTo);
        $this->assertContains('+224620006000', $gateway->sentTo);
    }

    /**
     * Garantie centrale du chantier : une panne fournisseur ne doit JAMAIS faire
     * échouer la validation du chargement elle-même (cf. rapport, "la
     * communication est une conséquence de l'action métier, pas une condition
     * de réussite de l'action").
     */
    public function test_a_gateway_failure_never_breaks_the_chargement_validation_response(): void
    {
        $gateway = $this->fakeSms();
        $gateway->shouldThrow = true;
        $this->app->instance(SmsGateway::class, $gateway);
        $this->enableRule(CommunicationModule::VENTES, CommunicationEvent::CHARGEMENT_VALIDE, CommunicationRecipientType::LIVREUR);

        ['commande' => $commande] = $this->makeCommandeAvecEquipe();

        $this->actingAs($this->user);
        $this->patch("/backoffice/ventes/{$commande->id}/valider");
        $this->post("/backoffice/ventes/{$commande->id}/statut/avancer");

        $ligne = $commande->fresh()->lignes()->first();
        $response = $this->post("/backoffice/ventes/{$commande->id}/statut/avancer", [
            'lignes' => [[
                'id' => $ligne->id,
                'quantite_chargee' => $ligne->quantite_demandee,
                'type_ecart' => 'conforme',
            ]],
        ]);

        // La transition métier a bien réussi malgré la panne SMS.
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);
        $this->assertSame('failed', MessageLog::sole()->status->value);
    }
}
