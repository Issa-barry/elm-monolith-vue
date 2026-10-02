<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppeLigne;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCommissionVenteFixtures;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Régularisation ponctuelle (13/09/2026) des enveloppes Consultant/Site générées à 0 GNF par
 * l'ancien bug de CommissionEnveloppeGenerator::genererDepuisContexte() (vente directe lisant
 * quantite_chargee, jamais renseignée sans étape de chargement). L'état "avant correctif" est
 * reconstitué à la main ici (insertion directe des lignes), le générateur lui-même étant déjà
 * corrigé et ne reproduisant donc plus ce bug (cf. CommandeVenteGrossisteCommissionTest).
 */
class CommissionsRegulariserVentesDirectesCommandTest extends TestCase
{
    use HasAdminSetup, HasCommissionVenteFixtures, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    private Categorie $categorie;

    private CommissionProcessus $processus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);

        $this->site = $this->creerSiteDepotTest();
        $this->categorie = $this->creerCategorieBouteilleTest();
        $this->processus = $this->creerProcessusVenteTest('facture_encaissee');
    }

    /**
     * Simule l'état laissé par l'ancien bug : une commande vente directe (sans véhicule), une
     * ligne avec quantite_demandee mais quantite_chargee restée null, un barème actif non nul, et
     * l'enveloppe/ligne/part telles que l'ancien code les aurait créées (montant/quantite à 0).
     */
    private function creerCommandeAvecEnveloppeCasseeAZero(
        int $quantiteDemandee,
        float $montantRegle,
        string $cibleType,
        ?string $vehiculeId = null,
    ): array {
        $regle = CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Consultant — catégorie',
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $this->categorie->id,
            'cible_type' => $cibleType,
            'mode' => CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montantRegle,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => ClientType::EXTERNE->value]);
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille', 'categorie_id' => $this->categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
        $variante = $produit->variantePrincipale()->first();

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $vehiculeId,
            'client_id' => $client->id,
            'nature_operation' => NatureOperation::VENTE_STANDARD->value,
            'statut' => StatutCommandeVente::FACTURATION,
            'total_commande' => $quantiteDemandee * 2000,
        ]);
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => $quantiteDemandee,
            'quantite_chargee' => null,
            'prix_usine_snapshot' => 1500,
            'prix_vente_snapshot' => 2000,
            'total_ligne' => $quantiteDemandee * 2000,
        ]);

        $cibleId = $cibleType === CommissionCibleType::CODE_SITE ? $this->site->id : $this->creerConsultantDesigneTest()->id;
        $beneficiaireType = $cibleType === CommissionCibleType::CODE_SITE
            ? CommissionEnveloppePart::TYPE_SITE
            : CommissionEnveloppePart::TYPE_PRESTATAIRE;

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $this->processus->id,
            'cible_type' => $cibleType,
            'cible_id' => $cibleId,
            'montant_total' => 0,
            'earned_at' => now(),
            'statut' => StatutCommission::CREEE->value,
        ]);
        CommissionEnveloppeLigne::create([
            'enveloppe_id' => $enveloppe->id,
            'source_ligne_type' => get_class($ligne),
            'source_ligne_id' => $ligne->id,
            'variante_id' => $variante->id,
            'categorie_id_snapshot' => $this->categorie->id,
            'commission_regle_id' => $regle->id,
            'quantite' => 0,
            'unite_calcul_snapshot' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant_ligne' => 0,
        ]);
        $part = CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => $beneficiaireType,
            'beneficiaire_id' => $cibleId,
            'montant_brut' => 0,
            'montant_net' => 0,
            'statut' => StatutCommission::CREEE->value,
            'origine' => 'theorique',
        ]);

        return compact('commande', 'enveloppe', 'part');
    }

    public function test_dry_run_naffiche_que_les_montants_a_corriger_sans_ecrire(): void
    {
        ['enveloppe' => $enveloppe] = $this->creerCommandeAvecEnveloppeCasseeAZero(
            quantiteDemandee: 3,
            montantRegle: 200,
            cibleType: CommissionCibleType::CODE_CONSULTANT,
        );

        $this->artisan('commissions:regulariser-ventes-directes', ['--organization' => [$this->org->id]])
            ->assertExitCode(0);

        $this->assertEquals(0, (float) $enveloppe->fresh()->montant_total);
    }

    public function test_apply_corrige_lenveloppe_la_ligne_et_la_part(): void
    {
        ['enveloppe' => $enveloppe, 'part' => $part] = $this->creerCommandeAvecEnveloppeCasseeAZero(
            quantiteDemandee: 3,
            montantRegle: 200,
            cibleType: CommissionCibleType::CODE_CONSULTANT,
        );

        $this->artisan('commissions:regulariser-ventes-directes', [
            '--organization' => [$this->org->id],
            '--apply' => true,
        ])->assertExitCode(0);

        $enveloppe->refresh();
        $part->refresh();
        $ligne = CommissionEnveloppeLigne::where('enveloppe_id', $enveloppe->id)->first();

        $this->assertEquals(600.0, (float) $enveloppe->montant_total); // 3 × 200
        $this->assertEquals(600.0, (float) $part->montant_brut);
        $this->assertEquals(600.0, (float) $part->montant_net);
        $this->assertEquals(3.0, (float) $ligne->quantite);
        $this->assertEquals(600.0, (float) $ligne->montant_ligne);
    }

    /**
     * Un 0 GNF causé par autre chose (commande avec véhicule) ne doit jamais être touché par ce
     * correctif ciblé sur la vente directe — sa cause est différente et doit être traitée à part.
     */
    public function test_ignore_une_enveloppe_a_zero_dont_la_commande_a_un_vehicule(): void
    {
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        ['enveloppe' => $enveloppe] = $this->creerCommandeAvecEnveloppeCasseeAZero(
            quantiteDemandee: 3,
            montantRegle: 200,
            cibleType: CommissionCibleType::CODE_CONSULTANT,
            vehiculeId: $vehicule->id,
        );

        $this->artisan('commissions:regulariser-ventes-directes', [
            '--organization' => [$this->org->id],
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertEquals(0, (float) $enveloppe->fresh()->montant_total);
    }

    /**
     * Un barème réellement à 0 GNF reste à 0 GNF — ce n'est pas un montant à fabriquer, c'est un
     * 0 légitime (décision AMOA #4, absence/nullité de règle = 0, jamais une erreur).
     */
    public function test_ne_fabrique_jamais_un_montant_si_le_bareme_est_reellement_a_zero(): void
    {
        ['enveloppe' => $enveloppe] = $this->creerCommandeAvecEnveloppeCasseeAZero(
            quantiteDemandee: 3,
            montantRegle: 0,
            cibleType: CommissionCibleType::CODE_SITE,
        );

        $this->artisan('commissions:regulariser-ventes-directes', [
            '--organization' => [$this->org->id],
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertEquals(0, (float) $enveloppe->fresh()->montant_total);
    }
}
