<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\MotifAjustementCommission;
use App\Enums\OrigineCommissionPart;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\CommissionAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommissionAjustementTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.manage']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);
    }

    private function makeLivreur(string $nom = 'Diallo'): Livreur
    {
        return Livreur::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'prenom' => 'Mamadou',
            'is_active' => true,
        ]);
    }

    private function makeCommande(): CommandeVente
    {
        $client = Client::create([
            'organization_id' => $this->org->id,
            'nom' => 'Client Test',
            'prenom' => 'Test',
            'is_active' => true,
            'cashback_eligible' => false,
        ]);

        $site = $this->user->sites()->wherePivot('is_default', true)->first();

        return CommandeVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'reference' => 'CMD-TEST-'.uniqid(),
            'statut' => 'livree',
            'total_commande' => 1000000,
        ]);
    }

    /** @return array{commission: CommissionVente, part: CommissionPart, livreur: Livreur} */
    private function makeCommissionAvecPart(float $montantNet = 300000.0): array
    {
        $livreur = $this->makeLivreur();

        $commission = CommissionVente::create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $this->makeCommande()->id,
            'vehicule_id' => null,
            'montant_commande' => 1000000,
            'montant_commission_totale' => $montantNet,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        $part = CommissionPart::create([
            'commission_vente_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => $livreur->nom_complet,
            'taux_commission' => 100,
            'montant_brut' => $montantNet,
            'frais_supplementaires' => 0,
            'montant_net' => $montantNet,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        return ['commission' => $commission, 'part' => $part, 'livreur' => $livreur];
    }

    /**
     * @param  array<string, float>  $repartition  nom => montant
     * @return array{commission: CommissionVente, parts: array<string, CommissionPart>}
     */
    private function makeCommissionAvecEquipe(array $repartition, ?string $vehiculeId = null): array
    {
        $livreurs = collect(array_keys($repartition))->mapWithKeys(fn (string $nom) => [$nom => $this->makeLivreur($nom)]);

        return $this->makeCommissionAvecEquipeLivreurs($livreurs->all(), $repartition, $vehiculeId);
    }

    /**
     * @param  array<string, Livreur>  $livreurs  nom => livreur (permet de réutiliser le même livreur sur 2 commandes)
     * @param  array<string, float>  $repartition  nom => montant
     * @return array{commission: CommissionVente, parts: array<string, CommissionPart>}
     */
    private function makeCommissionAvecEquipeLivreurs(array $livreurs, array $repartition, ?string $vehiculeId = null): array
    {
        $commission = CommissionVente::create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $this->makeCommande()->id,
            'vehicule_id' => $vehiculeId,
            'montant_commande' => 1000000,
            'montant_commission_totale' => array_sum($repartition),
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        $parts = [];
        foreach ($repartition as $nom => $montant) {
            $livreur = $livreurs[$nom];
            $parts[$nom] = CommissionPart::create([
                'commission_vente_id' => $commission->id,
                'type_beneficiaire' => 'livreur',
                'livreur_id' => $livreur->id,
                'beneficiaire_nom' => $livreur->nom_complet,
                'taux_commission' => 100,
                'montant_brut' => $montant,
                'frais_supplementaires' => 0,
                'montant_net' => $montant,
                'montant_verse' => 0,
                'statut' => 'impaye',
            ]);
        }

        return ['commission' => $commission, 'parts' => $parts];
    }

    private function makePeriode(string $statut = StatutPeriodePaiement::CALCULEE->value): PaiementPeriode
    {
        return PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'reference' => 'PAY-202606-0001',
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => '2026-06-01',
            'date_fin' => '2026-06-15',
            'statut' => $statut,
            'created_by' => $this->user->id,
        ]);
    }

    // ── Configuration théorique jamais modifiée ─────────────────────────────────

    public function test_ajustement_ne_modifie_jamais_equipe_livraison(): void
    {
        ['part' => $part, 'livreur' => $livreur] = $this->makeCommissionAvecPart();

        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'is_active' => true,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreur->id,
            'role' => 'chauffeur',
            'montant_par_pack' => 500,
            'ordre' => 1,
        ]);

        $snapshot = $equipe->livreurs()->first()->pivot->montant_par_pack;

        $this->actingAs($this->user)
            ->patch(route('comptabilite.ajustements.ajuster', ['type' => 'vente', 'partId' => $part->id]), [
                'montant' => 150000,
                'motif' => 'absence',
                'commentaire' => 'Absent le 10/06',
            ])
            ->assertRedirect();

        $equipe->refresh();
        $this->assertEquals($snapshot, $equipe->livreurs()->first()->pivot->montant_par_pack);
    }

    // ── Ajustement montant ───────────────────────────────────────────────────────

    public function test_ajuster_montant_cree_un_journal_et_ne_touche_pas_montant_net(): void
    {
        ['part' => $part] = $this->makeCommissionAvecPart(300000.0);

        $this->actingAs($this->user)
            ->patch(route('comptabilite.ajustements.ajuster', ['type' => 'vente', 'partId' => $part->id]), [
                'montant' => 200000,
                'motif' => 'correction',
                'commentaire' => 'Travail partiel',
            ])
            ->assertRedirect();

        $part->refresh();
        $this->assertSame(200000.0, (float) $part->montant_actuel);
        $this->assertSame(300000.0, (float) $part->montant_net, 'le montant théorique ne doit jamais être écrasé');

        $this->assertDatabaseHas('commission_part_adjustments', [
            'commission_part_type' => CommissionPart::class,
            'commission_part_id' => $part->id,
            'ancien_montant' => 300000.00,
            'nouveau_montant' => 200000.00,
            'motif' => 'correction',
        ]);
    }

    public function test_declarer_absence_met_le_montant_a_zero(): void
    {
        ['part' => $part] = $this->makeCommissionAvecPart(300000.0);

        $this->actingAs($this->user)
            ->post(route('comptabilite.ajustements.absence', ['type' => 'vente', 'partId' => $part->id]), [
                'commentaire' => 'Absent',
            ])
            ->assertRedirect();

        $part->refresh();
        $this->assertSame(0.0, (float) $part->montant_actuel);

        $this->assertDatabaseHas('commission_part_adjustments', [
            'commission_part_id' => $part->id,
            'motif' => 'absence',
            'nouveau_montant' => 0.00,
        ]);
    }

    public function test_part_deja_payee_ne_peut_plus_etre_ajustee(): void
    {
        ['part' => $part] = $this->makeCommissionAvecPart(300000.0);
        $part->update(['statut' => 'paye', 'montant_verse' => 300000]);
        $part->refresh();

        $this->assertFalse($part->peutEtreAjustee());

        $this->expectException(\LogicException::class);

        CommissionAdjustmentService::ajusterMontant(
            $part,
            100000.0,
            MotifAjustementCommission::CORRECTION,
            null,
            $this->user,
        );
    }

    // ── Remplaçant hors équipe théorique ─────────────────────────────────────────

    public function test_ajouter_remplacant_cree_une_part_origine_remplacement(): void
    {
        ['commission' => $commission] = $this->makeCommissionAvecPart();
        $remplacant = $this->makeLivreur('Camara');
        $periode = $this->makePeriode();

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.ajustements.remplacant', $periode), [
                'commission_type' => 'vente',
                'commission_id' => $commission->id,
                'type_beneficiaire' => 'livreur',
                'livreur_id' => $remplacant->id,
                'beneficiaire_nom' => $remplacant->nom_complet,
                'montant' => 120000,
                'commentaire' => 'Remplaçant du 10/06',
            ])
            ->assertRedirect();

        $nouvellePart = CommissionPart::where('livreur_id', $remplacant->id)->first();
        $this->assertNotNull($nouvellePart);
        $this->assertEquals(OrigineCommissionPart::REMPLACEMENT, $nouvellePart->origine);
        $this->assertSame(120000.0, (float) $nouvellePart->montant_actuel);
        $this->assertSame(
            0.0,
            (float) $nouvellePart->montant_net,
            'un remplaçant n\'a aucune allocation théorique : tout son montant est de l\'écart à compenser'
        );
    }

    // ── Gate de validation de la période ────────────────────────────────────────

    public function test_periode_ne_peut_pas_etre_validee_si_des_parts_ne_sont_pas_validees(): void
    {
        $this->travelTo('2026-06-10 12:00:00');
        ['part' => $part] = $this->makeCommissionAvecPart();
        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON->value);

        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));

        $periode->refresh();
        $this->assertSame(StatutPeriodePaiement::CALCULEE->value, $periode->statut->value);
        $this->assertDatabaseHas('paiement_fiches', ['periode_id' => $periode->id]);

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.valider', $periode))
            ->assertRedirect();

        $periode->refresh();
        $this->assertSame(
            StatutPeriodePaiement::CALCULEE->value,
            $periode->statut->value,
            'la période ne doit pas passer VALIDEE tant que la commission générée n\'est pas validée'
        );
    }

    public function test_periode_peut_etre_validee_une_fois_toutes_les_parts_validees(): void
    {
        $this->travelTo('2026-06-10 12:00:00');
        ['part' => $part] = $this->makeCommissionAvecPart();
        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON->value);

        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));
        $this->assertDatabaseHas('paiement_fiches', ['periode_id' => $periode->id]);

        $this->actingAs($this->user)
            ->post(route('comptabilite.ajustements.valider', ['type' => 'vente', 'partId' => $part->id]))
            ->assertRedirect();

        $part->refresh();
        $this->assertNotNull($part->validated_at);

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.valider', $periode))
            ->assertRedirect();

        $periode->refresh();
        $this->assertSame(StatutPeriodePaiement::VALIDEE->value, $periode->statut->value);
    }

    public function test_ecart_non_redistribue_bloque_la_validation_de_periode(): void
    {
        $this->travelTo('2026-06-10 12:00:00');
        ['parts' => $parts] = $this->makeCommissionAvecEquipe([
            'Oumar' => 60000,
            'Abdoulaye' => 45000,
            'Kadiatou' => 15000,
        ]);
        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON->value);

        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));

        // Abdoulaye absent, mis à 0, mais SANS redistribution aux deux autres.
        $this->actingAs($this->user)->patch(
            route('comptabilite.ajustements.ajuster', ['type' => 'vente', 'partId' => $parts['Abdoulaye']->id]),
            ['montant' => 0, 'motif' => 'absence']
        );

        foreach ($parts as $part) {
            $this->actingAs($this->user)->post(route('comptabilite.ajustements.valider', ['type' => 'vente', 'partId' => $part->id]));
        }

        $response = $this->actingAs($this->user)->post(route('comptabilite.periodes.valider', $periode));
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $periode->refresh();
        $this->assertSame(
            StatutPeriodePaiement::CALCULEE->value,
            $periode->statut->value,
            'la période ne doit pas être validée tant que les 10 000 GNF ne sont pas redistribués'
        );
    }

    public function test_redistribution_equilibree_permet_la_validation(): void
    {
        $this->travelTo('2026-06-10 12:00:00');
        ['parts' => $parts] = $this->makeCommissionAvecEquipe([
            'Oumar' => 60000,
            'Abdoulaye' => 45000,
            'Kadiatou' => 15000,
        ]);
        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON->value);

        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));

        // Abdoulaye réduit de 10 000, intégralement redistribué à Oumar : la somme reste 120 000.
        $this->actingAs($this->user)->patch(
            route('comptabilite.ajustements.ajuster', ['type' => 'vente', 'partId' => $parts['Abdoulaye']->id]),
            ['montant' => 35000, 'motif' => 'absence']
        );
        $this->actingAs($this->user)->patch(
            route('comptabilite.ajustements.ajuster', ['type' => 'vente', 'partId' => $parts['Oumar']->id]),
            ['montant' => 70000, 'motif' => 'travail_supplementaire']
        );

        foreach ($parts as $part) {
            $this->actingAs($this->user)->post(route('comptabilite.ajustements.valider', ['type' => 'vente', 'partId' => $part->id]));
        }

        $response = $this->actingAs($this->user)->post(route('comptabilite.periodes.valider', $periode));
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $periode->refresh();
        $this->assertSame(StatutPeriodePaiement::VALIDEE->value, $periode->statut->value);
    }

    public function test_redistribution_entre_deux_commandes_du_meme_vehicule_permet_la_validation(): void
    {
        // Le métier raisonne "je traite le véhicule pour la quinzaine", pas commande par
        // commande : un manque sur une commande peut être comblé sur une autre commande
        // du même véhicule, tant que le total du véhicule sur la période reste inchangé.
        $this->travelTo('2026-06-10 12:00:00');
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);

        ['parts' => $partsA] = $this->makeCommissionAvecEquipe(['Oumar' => 60000, 'Kadiatou' => 15000], $vehicule->id);
        ['parts' => $partsB] = $this->makeCommissionAvecEquipe(['Oumar' => 60000, 'Kadiatou' => 15000], $vehicule->id);

        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON->value);
        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));

        // Kadiatou absente sur les 2 commandes (-15 000 chacune = -30 000 pour le véhicule).
        $this->actingAs($this->user)->patch(
            route('comptabilite.ajustements.ajuster', ['type' => 'vente', 'partId' => $partsA['Kadiatou']->id]),
            ['montant' => 0, 'motif' => 'absence']
        );
        $this->actingAs($this->user)->patch(
            route('comptabilite.ajustements.ajuster', ['type' => 'vente', 'partId' => $partsB['Kadiatou']->id]),
            ['montant' => 0, 'motif' => 'absence']
        );

        // Compensation entièrement sur la commande B (+30 000), commande A non touchée :
        // chaque commande prise isolément est déséquilibrée (-15 000 / +15 000), mais le
        // véhicule sur l'ensemble de la période est à l'équilibre (0).
        $this->actingAs($this->user)->patch(
            route('comptabilite.ajustements.ajuster', ['type' => 'vente', 'partId' => $partsB['Oumar']->id]),
            ['montant' => 90000, 'motif' => 'travail_supplementaire']
        );

        foreach ([...array_values($partsA), ...array_values($partsB)] as $part) {
            $this->actingAs($this->user)->post(route('comptabilite.ajustements.valider', ['type' => 'vente', 'partId' => $part->id]));
        }

        $response = $this->actingAs($this->user)->post(route('comptabilite.periodes.valider', $periode));
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $periode->refresh();
        $this->assertSame(StatutPeriodePaiement::VALIDEE->value, $periode->statut->value);
    }

    public function test_valider_lot_valide_plusieurs_parts_en_une_fois(): void
    {
        ['part' => $part1] = $this->makeCommissionAvecPart(100000);
        ['part' => $part2] = $this->makeCommissionAvecPart(150000);
        $periode = $this->makePeriode();

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.ajustements.valider-lot', $periode), [
                'parts' => [
                    ['type' => 'vente', 'id' => $part1->id],
                    ['type' => 'vente', 'id' => $part2->id],
                ],
            ])
            ->assertRedirect();

        $this->assertNotNull($part1->refresh()->validated_at);
        $this->assertNotNull($part2->refresh()->validated_at);
    }

    // ── Le paiement doit refléter le montant ajusté, pas le montant théorique ──────

    public function test_calcul_de_fiche_utilise_le_montant_ajuste(): void
    {
        $this->travelTo('2026-06-10 12:00:00');
        ['part' => $part] = $this->makeCommissionAvecPart(300000.0);
        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON->value);

        $this->actingAs($this->user)
            ->patch(route('comptabilite.ajustements.ajuster', ['type' => 'vente', 'partId' => $part->id]), [
                'montant' => 180000,
                'motif' => 'correction',
            ]);

        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));

        $fiche = PaiementFiche::where('periode_id', $periode->id)->first();
        $this->assertNotNull($fiche);
        $this->assertSame(180000.0, (float) $fiche->montant_net);
    }

    // ── Autorisation ──────────────────────────────────────────────────────────────

    public function test_non_admin_ne_peut_pas_ajuster_une_commission(): void
    {
        ['part' => $part] = $this->makeCommissionAvecPart();

        Role::firstOrCreate(['name' => 'employe', 'guard_name' => 'web']);
        $employe = User::factory()->create(['organization_id' => $this->org->id]);
        $employe->assignRole('employe');

        $this->actingAs($employe)
            ->patch(route('comptabilite.ajustements.ajuster', ['type' => 'vente', 'partId' => $part->id]), [
                'montant' => 100000,
                'motif' => 'correction',
            ])
            ->assertStatus(403);
    }

    public function test_non_admin_ne_peut_pas_acceder_au_detail_vehicule(): void
    {
        $this->travelTo('2026-06-10 12:00:00');
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        $this->makeCommissionAvecEquipe(['Oumar' => 60000, 'Abdoulaye' => 45000, 'Kadiatou' => 15000], $vehicule->id);
        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON->value);
        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));

        Role::firstOrCreate(['name' => 'employe', 'guard_name' => 'web']);
        $employe = User::factory()->create(['organization_id' => $this->org->id]);
        $employe->assignRole('employe');

        $this->actingAs($employe)
            ->get(route('comptabilite.periodes.ajustements.vehicule', ['periode' => $periode, 'vehicule' => $vehicule->id]))
            ->assertStatus(403);
    }

    // ── Niveau 2 : détail d'un véhicule (équipe globale sur la période) ──────────

    public function test_detail_vehicule_affiche_les_commandes_et_lequipe_globale(): void
    {
        $this->travelTo('2026-06-10 12:00:00');
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        $this->makeCommissionAvecEquipe(['Oumar' => 60000, 'Abdoulaye' => 45000, 'Kadiatou' => 15000], $vehicule->id);
        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON->value);

        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.periodes.ajustements.vehicule', ['periode' => $periode, 'vehicule' => $vehicule->id]));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Comptabilite/Ajustements/Vehicule')
            ->where('vehicule.id', $vehicule->id)
            ->missing('commandes')
            ->has('beneficiaires', 3)
            ->has('beneficiaires.0.parts', 1)
        );
    }

    public function test_detail_vehicule_cumule_un_beneficiaire_sur_plusieurs_commandes(): void
    {
        // Le métier raisonne "équipe globale du véhicule sur la période" : un bénéficiaire
        // présent sur 2 commandes du même véhicule doit apparaître en une seule ligne,
        // avec les montants cumulés, pas une ligne par commande.
        $this->travelTo('2026-06-10 12:00:00');
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);

        $livreurs = ['Oumar' => $this->makeLivreur('Oumar'), 'Kadiatou' => $this->makeLivreur('Kadiatou')];
        ['parts' => $partsA] = $this->makeCommissionAvecEquipeLivreurs($livreurs, ['Oumar' => 60000, 'Kadiatou' => 15000], $vehicule->id);
        ['parts' => $partsB] = $this->makeCommissionAvecEquipeLivreurs($livreurs, ['Oumar' => 60000, 'Kadiatou' => 15000], $vehicule->id);

        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON->value);
        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.periodes.ajustements.vehicule', ['periode' => $periode, 'vehicule' => $vehicule->id]));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Comptabilite/Ajustements/Vehicule')
            ->missing('commandes')
            ->has('beneficiaires', 2)
        );

        $oumar = collect($response->viewData('page')['props']['beneficiaires'])
            ->firstWhere('beneficiaire_nom', $partsA['Oumar']->beneficiaire_nom);

        $this->assertNotNull($oumar);
        $this->assertSame(120000.0, $oumar['theorique']);
        $this->assertCount(2, $oumar['parts']);
        $this->assertArrayNotHasKey('reference', $oumar['parts'][0], 'la référence de commande ne doit jamais être exposée à l\'écran d\'ajustement');
    }

    public function test_detail_vehicule_404_si_aucune_commande_pour_ce_vehicule(): void
    {
        $periode = $this->makePeriode();

        $this->actingAs($this->user)
            ->get(route('comptabilite.periodes.ajustements.vehicule', ['periode' => $periode, 'vehicule' => 'un-id-qui-nexiste-pas']))
            ->assertStatus(404);
    }

    // ── Ajustement / absence agrégés sur plusieurs commandes ─────────────────────

    public function test_ajuster_groupe_repartit_un_montant_global_sur_les_parts_dun_beneficiaire(): void
    {
        // Le responsable métier ne saisit qu'un total pour la quinzaine : la répartition
        // par commande (au prorata du théorique) est un détail interne, jamais exposé.
        $this->travelTo('2026-06-10 12:00:00');
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        ['parts' => $partsA] = $this->makeCommissionAvecEquipe(['Oumar' => 60000], $vehicule->id);
        ['parts' => $partsB] = $this->makeCommissionAvecEquipe(['Oumar' => 30000], $vehicule->id);
        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON->value);
        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.ajustements.ajuster-groupe', $periode), [
                'parts' => [
                    ['type' => 'vente', 'id' => $partsA['Oumar']->id],
                    ['type' => 'vente', 'id' => $partsB['Oumar']->id],
                ],
                'montant' => 60000,
                'motif' => 'correction',
                'commentaire' => 'Correction sur la quinzaine',
            ])
            ->assertRedirect();

        // Théorique 60 000 / 30 000 (ratio 2:1) réparti sur un nouveau total de 60 000 → 40 000 / 20 000.
        $this->assertSame(40000.0, (float) $partsA['Oumar']->refresh()->montant_actuel);
        $this->assertSame(20000.0, (float) $partsB['Oumar']->refresh()->montant_actuel);
    }

    public function test_ajuster_groupe_refuse_un_montant_inferieur_au_deja_verse(): void
    {
        $this->travelTo('2026-06-10 12:00:00');
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        ['parts' => $partsA] = $this->makeCommissionAvecEquipe(['Oumar' => 60000], $vehicule->id);
        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON->value);
        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));

        $partsA['Oumar']->update(['statut' => 'paye', 'montant_verse' => 60000]);

        $response = $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.ajustements.ajuster-groupe', $periode), [
                'parts' => [
                    ['type' => 'vente', 'id' => $partsA['Oumar']->id],
                ],
                'montant' => 30000,
                'motif' => 'correction',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(60000.0, (float) $partsA['Oumar']->refresh()->montant_a_payer);
    }

    public function test_ajuster_multiple_augmente_et_diminue_plusieurs_beneficiaires_en_une_action(): void
    {
        // Le responsable équilibre l'écart en une seule popup : diminue Abdoulaye, augmente
        // Oumar, dans le même envoi — au lieu de rouvrir l'ajustement un bénéficiaire à la fois.
        $this->travelTo('2026-06-10 12:00:00');
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        ['parts' => $parts] = $this->makeCommissionAvecEquipe([
            'Oumar' => 60000,
            'Abdoulaye' => 45000,
            'Kadiatou' => 15000,
        ], $vehicule->id);
        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON->value);
        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));

        $response = $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.ajustements.ajuster-multiple', $periode), [
                'groups' => [
                    [
                        'label' => 'Abdoulaye',
                        'parts' => [['type' => 'vente', 'id' => $parts['Abdoulaye']->id]],
                        'montant' => 35000,
                    ],
                    [
                        'label' => 'Oumar',
                        'parts' => [['type' => 'vente', 'id' => $parts['Oumar']->id]],
                        'montant' => 70000,
                    ],
                ],
                'motif' => 'correction',
                'commentaire' => 'Rééquilibrage de la quinzaine',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertSame(35000.0, (float) $parts['Abdoulaye']->refresh()->montant_actuel);
        $this->assertSame(70000.0, (float) $parts['Oumar']->refresh()->montant_actuel);
        $this->assertSame(15000.0, (float) $parts['Kadiatou']->refresh()->montant_a_payer, 'un bénéficiaire non inclus dans les groupes ne doit pas bouger');
    }

    public function test_ajuster_multiple_est_atomique_si_un_groupe_echoue(): void
    {
        // Si un bénéficiaire du lot est déjà entièrement versé pour un montant inférieur, la
        // transaction doit annuler aussi les autres ajustements du même envoi (tout ou rien).
        $this->travelTo('2026-06-10 12:00:00');
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        ['parts' => $parts] = $this->makeCommissionAvecEquipe([
            'Oumar' => 60000,
            'Abdoulaye' => 45000,
        ], $vehicule->id);
        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON->value);
        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));

        $parts['Abdoulaye']->update(['statut' => 'paye', 'montant_verse' => 45000]);

        $response = $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.ajustements.ajuster-multiple', $periode), [
                'groups' => [
                    [
                        'label' => 'Oumar',
                        'parts' => [['type' => 'vente', 'id' => $parts['Oumar']->id]],
                        'montant' => 90000,
                    ],
                    [
                        'label' => 'Abdoulaye',
                        'parts' => [['type' => 'vente', 'id' => $parts['Abdoulaye']->id]],
                        'montant' => 0,
                    ],
                ],
                'motif' => 'correction',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(60000.0, (float) $parts['Oumar']->refresh()->montant_a_payer, "l'ajustement d'Oumar doit être annulé car Abdoulaye a échoué");
    }

    public function test_absence_groupe_met_toutes_les_parts_dun_beneficiaire_a_zero(): void
    {
        $this->travelTo('2026-06-10 12:00:00');
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        ['parts' => $partsA] = $this->makeCommissionAvecEquipe(['Kadiatou' => 15000], $vehicule->id);
        ['parts' => $partsB] = $this->makeCommissionAvecEquipe(['Kadiatou' => 15000], $vehicule->id);
        $periode = $this->makePeriode(StatutPeriodePaiement::BROUILLON->value);
        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.ajustements.absence-groupe', $periode), [
                'parts' => [
                    ['type' => 'vente', 'id' => $partsA['Kadiatou']->id],
                    ['type' => 'vente', 'id' => $partsB['Kadiatou']->id],
                ],
                'commentaire' => 'Absente toute la quinzaine',
            ])
            ->assertRedirect();

        $this->assertSame(0.0, (float) $partsA['Kadiatou']->refresh()->montant_actuel);
        $this->assertSame(0.0, (float) $partsB['Kadiatou']->refresh()->montant_actuel);
    }
}
