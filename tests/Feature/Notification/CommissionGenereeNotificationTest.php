<?php

namespace Tests\Feature\Notification;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionLogistique;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutTransfert;
use App\Jobs\DispatchPushNotificationsJob;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Parametre;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use App\Notifications\CommissionGenereeNotification;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\TransfertLogistiqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Phase 1 archi notifications (2026-08-27, cf. rapport) : commission générée
 * avec succès notifie les bénéficiaires réellement connectés (proprietaire,
 * livreur) — jamais `site`/`consultant` (aucun compte utilisateur, non
 * configurés ici donc silencieusement absents des enveloppes générées, cf.
 * décision AMOA #4 "absence de règle = 0").
 */
class CommissionGenereeNotificationTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, MakesClientProfiles, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Vente Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    public function test_commande_vente_notifie_proprietaire_et_livreur_connectes(): void
    {
        Notification::fake();
        Queue::fake();

        $processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'facture_encaissee',
            'strategie_ancrage_site' => 'operation',
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Règle proprietaire',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'mode' => CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 600,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Règle équipe livraison',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 300,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        // Propriétaire NON concerné par cette commande : ne doit jamais être notifié.
        $autreProprietaireUser = $this->makeProprietaireUser($this->org);

        $proprietaireUser = $this->makeProprietaireUser($this->org);
        $livreurUser = $this->makeLivreurUser($this->org);

        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaireUser->proprietaire->id,
            'capacite_packs' => 100,
        ]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreurUser->livreur->id,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);

        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id,
            'categorie_id' => $categorie->id,
            'livreur_id' => $livreurUser->livreur->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => 300,
            'effective_from' => now()->subDay(),
        ]);

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit '.uniqid(), 'categorie_id' => $categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
        $variante = $produit->variantePrincipale()->first();
        $this->seedVarianteStockSuffisant($variante, $this->defaultSite);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 5,
            'prix_usine_snapshot' => (float) $variante->prix_usine,
            'prix_vente_snapshot' => (float) $variante->prix_vente,
            'total_ligne' => 5 * (float) $variante->prix_vente,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [
            ['id' => $ligne->id, 'quantite_chargee' => 5, 'type_ecart' => 'conforme'],
        ]);

        CommissionEnveloppeGenerator::genererPourCommandeVente($commande->fresh());

        Notification::assertSentTo($proprietaireUser, CommissionGenereeNotification::class);
        Notification::assertSentTo($livreurUser, CommissionGenereeNotification::class);
        Notification::assertNotSentTo($autreProprietaireUser, CommissionGenereeNotification::class);

        // Web Push/Expo (7.08.2026) : même événement, poussé pour chaque bénéficiaire réel
        // uniquement — jamais pour un propriétaire non concerné.
        Queue::assertPushed(DispatchPushNotificationsJob::class, fn (DispatchPushNotificationsJob $job) => $job->userIds === [$proprietaireUser->id]
            && $job->payload['data']['type'] === 'commission.generated'
            && $job->payload['data']['commande_id'] === $commande->id);
        Queue::assertPushed(DispatchPushNotificationsJob::class, fn (DispatchPushNotificationsJob $job) => $job->userIds === [$livreurUser->id]);
        Queue::assertPushed(DispatchPushNotificationsJob::class, 2);

        // Nettoyage des messages (2026-08-28) : `message` (API/cloche) ne contient plus le
        // montant, mais le push (Expo/Web Push, seul champ `body`, pas de `montant` séparé)
        // doit rester autonome — le montant y réapparaît, construit par PushBodyFormatter.
        Queue::assertPushed(DispatchPushNotificationsJob::class, fn (DispatchPushNotificationsJob $job) => $job->userIds === [$proprietaireUser->id]
            && str_contains($job->payload['body'], 'GNF')
            && str_contains($job->payload['body'], $commande->reference));
    }

    public function test_commission_logistique_notifie_le_livreur_beneficiaire(): void
    {
        Notification::fake();
        Queue::fake();

        $org = $this->org;
        Parametre::setDeclencheurCommissionLogistique($org->id, DeclencheurCommissionLogistique::CHARGEMENT_VALIDE);

        $siteSource = Site::create(['organization_id' => $org->id, 'nom' => 'Source', 'type' => 'depot', 'localisation' => 'Conakry']);
        $siteDest = Site::create(['organization_id' => $org->id, 'nom' => 'Destination', 'type' => 'siege', 'localisation' => 'Conakry']);

        $livreurUser = $this->makeLivreurUser($org);

        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'livraison_vente' => false,
            'livraison_logistique' => true,
            'capacite_packs' => 500,
        ]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Logistique Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreurUser->livreur->id, 'taux_commission' => 100]);

        $produit = $this->makeProduitAvecVariante($org, ['nom' => 'Eau 19L '.uniqid()], ['prix_vente' => 5000]);
        $variante = $produit->variantePrincipale()->first();
        $this->seedVarianteStockSuffisant($variante, $siteSource);

        $transfert = TransfertLogistique::create([
            'organization_id' => $org->id,
            'site_source_id' => $siteSource->id,
            'site_destination_id' => $siteDest->id,
            'vehicule_id' => $vehicule->id,
            'equipe_livraison_id' => $equipe->id,
            'statut' => StatutTransfert::CHARGEMENT,
            'created_by' => $this->user->id,
        ]);
        TransfertLigne::create([
            'transfert_logistique_id' => $transfert->id,
            'variante_id' => $variante->id,
            'quantite_demandee' => 100,
            'quantite_chargee' => 100,
        ]);

        $this->actingAs($this->user);
        TransfertLogistiqueService::avancerStatut($transfert);

        Notification::assertSentTo($livreurUser, CommissionGenereeNotification::class);

        Queue::assertPushed(DispatchPushNotificationsJob::class, fn (DispatchPushNotificationsJob $job) => $job->userIds === [$livreurUser->id]
            && $job->payload['data']['type'] === 'commission.generated'
            && $job->payload['data']['transfert_id'] === $transfert->id);
    }
}
