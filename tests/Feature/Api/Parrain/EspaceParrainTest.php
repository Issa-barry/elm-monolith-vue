<?php

namespace Tests\Feature\Api\Parrain;

use App\Enums\StatutCommission;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\Parrain;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Comptes\RattachementCompteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Espace parrain (ADR 0050, lot 3) : un compte ne lit que les données du parrain auquel un
 * responsable l'a rattaché, jamais celles d'un autre bénéficiaire ni d'une autre organisation.
 */
class EspaceParrainTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private const ROUTES = ['synthese', 'vehicules', 'commissions', 'paiements'];

    private Organization $org;

    private Parrain $parrain;

    private User $compte;

    private Vehicule $vehicule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->parrain = Parrain::factory()->create(['organization_id' => $this->org->id]);
        $this->compte = $this->compteClient($this->org);
        $this->parrain->forceFill(['user_id' => $this->compte->id])->save();
        $this->vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'parrain_id' => $this->parrain->id]);
    }

    private function compteClient(?Organization $org): User
    {
        $this->ensureClientRoles();
        $compte = User::factory()->create(['organization_id' => $org?->id]);
        $compte->assignRole('client');

        return $compte;
    }

    private function url(string $route): string
    {
        return route("client.parrain.{$route}");
    }

    private function commande(?Vehicule $vehicule = null, ?string $reference = null): CommandeVente
    {
        return CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'vehicule_id' => ($vehicule ?? $this->vehicule)->id,
            ...($reference ? ['reference' => $reference] : []),
        ]);
    }

    private function enveloppe(CommandeVente|TransfertLogistique $source, string $date = '2026-10-05'): CommissionEnveloppe
    {
        return CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => $source::class,
            'source_id' => $source->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 1000,
            'montant_verse' => 0,
            'earned_at' => $date,
            'statut' => StatutCommission::IMPAYE,
        ]);
    }

    private function part(CommissionEnveloppe $enveloppe, float $montant, array $attributs = []): CommissionEnveloppePart
    {
        return CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PARRAIN,
            'beneficiaire_id' => $this->parrain->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
            ...$attributs,
        ]);
    }

    // ── Accès ───────────────────────────────────────────────────────────────

    public function test_un_compte_non_authentifie_est_refuse(): void
    {
        foreach (self::ROUTES as $route) {
            $this->getJson($this->url($route))->assertUnauthorized();
        }
    }

    public function test_un_compte_sans_parrain_rattache_recoit_404_partout(): void
    {
        Sanctum::actingAs($this->compteClient($this->org), ['*']);

        foreach (self::ROUTES as $route) {
            $this->getJson($this->url($route))->assertNotFound();
        }
    }

    public function test_un_numero_identique_ne_donne_aucun_acces(): void
    {
        $telephone = '+224620000777';
        Parrain::factory()->create(['organization_id' => $this->org->id, 'telephone' => $telephone]);
        $meme = $this->compteClient($this->org);
        $meme->update(['telephone' => $telephone]);

        Sanctum::actingAs($meme, ['*']);

        $this->getJson($this->url('synthese'))->assertNotFound();
        $this->getJson(route('api.auth.me'))->assertOk()->assertJsonPath('context.parrain_id', null);
    }

    // ADR 0055 : le compte d'un agent entre dans l'espace client, mais sans parrain rattaché : 404.
    public function test_le_compte_d_un_membre_du_personnel_sans_parrain_rattache_ne_voit_rien(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $staff = User::factory()->create(['organization_id' => $this->org->id]);
        $staff->assignRole('manager');
        Sanctum::actingAs($staff, ['*']);

        $this->getJson($this->url('synthese'))->assertNotFound();
    }

    public function test_le_detachement_coupe_l_acces_des_la_requete_suivante(): void
    {
        Sanctum::actingAs($this->compte, ['*']);
        $this->getJson($this->url('synthese'))->assertOk();

        app(RattachementCompteService::class)->detacher($this->parrain, User::factory()->create(['organization_id' => $this->org->id]));

        foreach (self::ROUTES as $route) {
            $this->getJson($this->url($route))->assertNotFound();
        }
        $this->getJson(route('api.auth.me'))->assertOk()->assertJsonPath('context.parrain_id', null);
    }

    public function test_un_parrain_desactive_garde_l_acces_a_son_historique(): void
    {
        $this->part($this->enveloppe($this->commande()), 200);
        $this->parrain->update(['is_active' => false]);

        Sanctum::actingAs($this->compte, ['*']);

        $this->getJson($this->url('synthese'))
            ->assertOk()
            ->assertJsonPath('parrain.is_active', false)
            ->assertJsonPath('totaux.total_genere', 200);
    }

    // ── /me ─────────────────────────────────────────────────────────────────

    public function test_me_expose_le_parrain_sans_changer_les_autres_champs(): void
    {
        Sanctum::actingAs($this->compte, ['*']);

        $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('context.parrain_id', $this->parrain->id)
            ->assertJsonPath('context.client_id', null)
            ->assertJsonPath('context.proprietaire_id', null)
            ->assertJsonPath('context.livreur_id', null);
    }

    // ── Isolation ───────────────────────────────────────────────────────────

    public function test_un_parametre_ne_peut_pas_designer_un_autre_parrain(): void
    {
        $autre = Parrain::factory()->create(['organization_id' => $this->org->id]);
        $commande = $this->commande();
        $this->part($this->enveloppe($commande), 200);
        $this->part($this->enveloppe($commande), 900, ['beneficiaire_id' => $autre->id]);

        Sanctum::actingAs($this->compte, ['*']);

        $this->getJson($this->url('commissions').'?parrain_id='.$autre->id.'&beneficiaire_id='.$autre->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.montant_a_payer', 200);

        $this->getJson($this->url('synthese').'?parrain_id='.$autre->id)
            ->assertJsonPath('parrain.id', $this->parrain->id)
            ->assertJsonPath('totaux.total_genere', 200);
    }

    public function test_seule_la_part_du_parrain_est_lue_sur_une_operation(): void
    {
        $enveloppe = $this->enveloppe($this->commande());
        $this->part($enveloppe, 200);
        foreach ([[CommissionEnveloppePart::TYPE_LIVREUR, 500], [CommissionEnveloppePart::TYPE_PROPRIETAIRE, 300]] as [$type, $montant]) {
            $this->part($enveloppe, $montant, ['beneficiaire_type' => $type, 'beneficiaire_id' => $this->parrain->id]);
        }

        Sanctum::actingAs($this->compte, ['*']);

        $this->getJson($this->url('commissions'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('resume.total_genere', 200);
        $this->getJson($this->url('synthese'))->assertJsonPath('totaux.total_genere', 200);
    }

    public function test_les_donnees_d_une_autre_organisation_ne_sont_jamais_lues(): void
    {
        $autreOrg = Organization::factory()->create();
        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $autreOrg->id,
            'source_type' => CommandeVente::class,
            'source_id' => $this->commande()->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($autreOrg->id, CommissionProcessus::CODE_VENTE)->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 1000,
            'montant_verse' => 0,
            'earned_at' => '2026-10-05',
            'statut' => StatutCommission::IMPAYE,
        ]);
        $this->part($enveloppe, 700);

        Sanctum::actingAs($this->compte, ['*']);

        $this->getJson($this->url('commissions'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url('synthese'))->assertJsonPath('totaux.total_genere', 0);
    }

    public function test_aucune_donnee_d_equipe_de_client_ni_de_tresorerie_n_est_exposee(): void
    {
        $commande = $this->commande();
        $this->part($this->enveloppe($commande), 200);
        $fiche = $this->fiche();
        PaiementFichePaiement::withoutEvents(fn () => PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $this->org->id,
            'montant' => 50,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-10-20',
            'note' => 'NOTE-INTERNE',
        ]));

        Sanctum::actingAs($this->compte, ['*']);

        $commission = $this->getJson($this->url('commissions'))->assertOk()->json('data.0');
        $this->assertEqualsCanonicalizing(
            ['id', 'date', 'reference', 'processus_label', 'vehicule', 'montant_unitaire', 'montant_a_payer', 'montant_verse', 'montant_restant', 'statut', 'statut_label'],
            array_keys($commission),
        );
        $this->assertEqualsCanonicalizing(['id', 'nom_vehicule', 'immatriculation'], array_keys($commission['vehicule']));

        $paiement = $this->getJson($this->url('paiements'))->assertOk()->json('data.0');
        $this->assertEqualsCanonicalizing(
            ['id', 'date', 'montant', 'mode_paiement', 'mode_paiement_label', 'reference_paiement', 'fiche_reference', 'periode_reference'],
            array_keys($paiement),
        );
        $this->assertSame('Espèces', $paiement['mode_paiement_label']);

        $vehicule = $this->getJson($this->url('vehicules'))->assertOk()->json('data.0');
        $this->assertEqualsCanonicalizing(
            ['id', 'nom_vehicule', 'immatriculation', 'type_label', 'site_nom', 'is_active', 'parts'],
            array_keys($vehicule),
        );
    }

    // ── Commissions ─────────────────────────────────────────────────────────

    public function test_les_totaux_reprennent_les_compartiments_sans_les_commissions_annulees(): void
    {
        $this->part($this->enveloppe($this->commande()), 100, ['statut' => StatutCommission::CREEE]);
        $this->part($this->enveloppe($this->commande()), 200);
        $this->part($this->enveloppe($this->commande()), 300, ['statut' => StatutCommission::PARTIEL, 'montant_verse' => 120]);
        $this->part($this->enveloppe($this->commande()), 400, ['statut' => StatutCommission::PAYE, 'montant_verse' => 400]);
        $this->part($this->enveloppe($this->commande()), 999, ['statut' => StatutCommission::ANNULEE]);

        Sanctum::actingAs($this->compte, ['*']);

        $this->getJson($this->url('synthese'))
            ->assertOk()
            ->assertJsonPath('nb_vehicules', 1)
            ->assertJsonPath('totaux.total_genere', 1000)
            ->assertJsonPath('totaux.en_attente_periode', 100)
            ->assertJsonPath('totaux.payable', 380)
            ->assertJsonPath('totaux.deja_paye', 520)
            ->assertJsonPath('totaux.reste_a_payer', 380);

        $this->getJson($this->url('commissions'))->assertJsonCount(4, 'data');
    }

    public function test_un_montant_ajuste_par_un_responsable_prime_sur_le_montant_theorique(): void
    {
        $this->part($this->enveloppe($this->commande()), 200, ['montant_actuel' => 150]);

        Sanctum::actingAs($this->compte, ['*']);

        $this->getJson($this->url('commissions'))
            ->assertJsonPath('data.0.montant_a_payer', 150)
            ->assertJsonPath('data.0.montant_restant', 150)
            ->assertJsonPath('resume.total_genere', 150);
    }

    public function test_les_filtres_de_periode_de_vehicule_et_de_statut_s_appliquent(): void
    {
        $autreVehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'parrain_id' => $this->parrain->id]);
        $this->part($this->enveloppe($this->commande(), '2026-09-10'), 100);
        $this->part($this->enveloppe($this->commande($autreVehicule), '2026-10-10'), 200, ['statut' => StatutCommission::PAYE, 'montant_verse' => 200]);

        Sanctum::actingAs($this->compte, ['*']);

        $total = fn (array $filtres) => $this->getJson($this->url('commissions').'?'.http_build_query($filtres))
            ->assertOk()
            ->json('resume.total_genere');

        $this->assertEquals(100, $total(['date_fin' => '2026-09-30']));
        $this->assertEquals(200, $total(['date_debut' => '2026-10-01']));
        $this->assertEquals(200, $total(['vehicule_id' => $autreVehicule->id]));
        $this->assertEquals(100, $total(['statut' => StatutCommission::IMPAYE->value]));
        $this->assertEquals(0, $total(['vehicule_id' => Vehicule::factory()->create(['organization_id' => $this->org->id])->id]));
    }

    public function test_une_part_de_transfert_porte_sa_reference_et_son_vehicule(): void
    {
        $site = Site::factory()->create(['organization_id' => $this->org->id]);
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $site->id,
            'site_destination_id' => Site::factory()->create(['organization_id' => $this->org->id])->id,
            'vehicule_id' => $this->vehicule->id,
            'created_by' => User::factory()->create(['organization_id' => $this->org->id])->id,
        ]);
        $this->part($this->enveloppe($transfert), 200);

        Sanctum::actingAs($this->compte, ['*']);

        $this->getJson($this->url('commissions').'?vehicule_id='.$this->vehicule->id)
            ->assertOk()
            ->assertJsonPath('data.0.reference', $transfert->fresh()->reference)
            ->assertJsonPath('data.0.vehicule.id', $this->vehicule->id);
    }

    public function test_la_liste_est_triee_du_plus_recent_au_plus_ancien_et_paginee(): void
    {
        $this->part($this->enveloppe($this->commande(), '2026-09-01'), 100);
        $this->part($this->enveloppe($this->commande(), '2026-10-15'), 200);
        $this->part($this->enveloppe($this->commande(), '2026-10-01'), 300);

        Sanctum::actingAs($this->compte, ['*']);

        $this->getJson($this->url('commissions').'?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.date', '2026-10-15')
            ->assertJsonPath('data.1.date', '2026-10-01')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('resume.total_genere', 600);
    }

    public function test_un_parrain_remplace_garde_ses_commissions_mais_plus_le_vehicule(): void
    {
        $this->part($this->enveloppe($this->commande()), 200);
        $this->vehicule->update(['parrain_id' => null]);

        Sanctum::actingAs($this->compte, ['*']);

        $this->getJson($this->url('vehicules'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url('commissions'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.vehicule.id', $this->vehicule->id);
        $this->getJson($this->url('synthese'))->assertJsonPath('nb_vehicules', 0)->assertJsonPath('totaux.total_genere', 200);
    }

    // ── Paiements ───────────────────────────────────────────────────────────

    private function fiche(?Parrain $parrain = null): PaiementFiche
    {
        $periode = PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'type' => TypePeriodePaiement::PARRAIN->value,
            'reference' => 'PAY-'.fake()->unique()->numerify('######').'-P1-PAR',
            'date_debut' => '2026-10-01',
            'date_fin' => '2026-10-15',
            'statut' => StatutPeriodePaiement::VALIDEE->value,
        ]);

        return PaiementFiche::create([
            'organization_id' => $this->org->id,
            'periode_id' => $periode->id,
            'reference' => 'FP-'.fake()->unique()->numerify('######'),
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PARRAIN,
            'beneficiaire_id' => ($parrain ?? $this->parrain)->id,
            'beneficiaire_nom' => 'Parrain',
            'montant_brut' => 1000,
            'total_deductions' => 0,
            'montant_net' => 1000,
            'montant_paye' => 0,
        ]);
    }

    public function test_seuls_les_versements_du_parrain_sont_listes(): void
    {
        $paiement = fn (PaiementFiche $fiche, string $date) => PaiementFichePaiement::withoutEvents(fn () => PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $this->org->id,
            'montant' => 100,
            'mode_paiement' => 'mobile_money',
            'date_paiement' => $date,
        ]));

        $fiche = $this->fiche();
        $paiement($fiche, '2026-10-01');
        $paiement($fiche, '2026-10-20');
        $paiement($this->fiche(Parrain::factory()->create(['organization_id' => $this->org->id])), '2026-10-21');

        Sanctum::actingAs($this->compte, ['*']);

        $this->getJson($this->url('paiements'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.date', '2026-10-20')
            ->assertJsonPath('data.0.fiche_reference', $fiche->reference);

        $this->getJson($this->url('paiements').'?date_fin=2026-10-10')->assertJsonCount(1, 'data');
    }

    public function test_des_filtres_invalides_sont_refuses(): void
    {
        Sanctum::actingAs($this->compte, ['*']);

        $this->getJson($this->url('commissions').'?statut=annulee')->assertUnprocessable();
        $this->getJson($this->url('commissions').'?date_debut=2026-10-10&date_fin=2026-10-01')->assertUnprocessable();
        $this->getJson($this->url('paiements').'?per_page=1000')->assertUnprocessable();
    }

    // ── Véhicules ───────────────────────────────────────────────────────────

    public function test_seuls_les_vehicules_du_parrain_sont_listes(): void
    {
        Vehicule::factory()->create(['organization_id' => $this->org->id, 'parrain_id' => Parrain::factory()->create(['organization_id' => $this->org->id])->id]);
        Vehicule::factory()->create(['organization_id' => $this->org->id]);

        Sanctum::actingAs($this->compte, ['*']);

        $this->getJson($this->url('vehicules'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->vehicule->id)
            ->assertJsonPath('data.0.parts', []);
    }
}
