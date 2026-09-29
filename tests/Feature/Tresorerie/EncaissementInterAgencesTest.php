<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\EvenementComptable;
use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\EcritureComptable;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Models\TiersComptable;
use App\Models\User;
use App\Services\Tresorerie\AgenceEncaissementResolver;
use App\Services\Tresorerie\DetteInterAgencesService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Encaisser dans une agence une commande d'une autre agence (ADR 0012, lot 1).
 *
 * Règles verrouillées : l'agence d'encaissement est l'une des agences de l'utilisateur, jamais une
 * autre ; elle exige `factures.encaisser_autre_agence` quand elle diffère de celle de la commande ;
 * moyens et caisse dédiée sont ceux de l'agence d'encaissement ; l'argent entre dans la trésorerie
 * de l'agence qui encaisse tandis que client et vente restent à l'agence de la commande (deux
 * pièces reliées par le compte de liaison 181) ; un encaissement dans la même agence reste
 * strictement inchangé.
 */
class EncaissementInterAgencesTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $agenceA;

    private Site $agenceB;

    private User $agentB;

    private CompteTresorerie $orangeA;

    private CompteTresorerie $orangeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['factures.encaisser', 'ventes.read', 'ventes.update', 'ventes.annuler_exceptionnel']);
        $this->agenceA = $this->user->sites()->firstOrFail();
        $this->agenceB = $this->creerSite('Agence Kindia');

        $this->agentB = $this->creerUtilisateurNonAdmin(
            $this->agenceB,
            ['factures.encaisser', AgenceEncaissementResolver::PERMISSION],
            'Mariama',
            'Kindia',
        );

        $this->orangeA = $this->creerSupportAgence($this->agenceA->id, 'mobile_money', '561100', 'orange_money');
        $this->orangeB = $this->creerSupportAgence($this->agenceB->id, 'mobile_money', '561100', 'orange_money');
    }

    private function creerSite(string $nom, ?Organization $org = null): Site
    {
        return Site::create([
            'organization_id' => ($org ?? $this->org)->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => $nom,
        ]);
    }

    private function facture(?Site $site = null, float $montant = 500_000): FactureVente
    {
        $site ??= $this->agenceA;

        $commande = CommandeVente::factory()->create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'statut' => StatutCommandeVente::LIVREE,
            'total_commande' => $montant,
        ]);

        return FactureVente::factory()->create([
            'organization_id' => $site->organization_id,
            'commande_vente_id' => $commande->id,
            'site_id' => $site->id,
            'montant_net' => $montant,
        ]);
    }

    /** @param  array<string, mixed>  $surcharge */
    private function encaisser(FactureVente $facture, User $auteur, array $surcharge = [])
    {
        return $this->actingAs($auteur)->post(route('encaissements.store', $facture), array_merge([
            'montant' => 100_000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $this->orangeB->id,
            'reference_paiement' => 'OM-REF-1',
            'site_encaissement_id' => $this->agenceB->id,
        ], $surcharge));
    }

    private function piece(EncaissementVente $encaissement, EvenementComptable $evenement): ?PieceComptable
    {
        return PieceComptable::where('source_type', $encaissement->getMorphClass())
            ->where('source_id', $encaissement->id)
            ->where('type_evenement', $evenement->value)
            ->first();
    }

    /** @return array<string, array{debit: float, credit: float, site_id: ?string, tiers: ?string}> */
    private function lignes(PieceComptable $piece): array
    {
        return $piece->lignes()->with('compte')->get()
            ->mapWithKeys(fn ($l) => [$l->compte->numero => [
                'debit' => (float) $l->debit,
                'credit' => (float) $l->credit,
                'site_id' => $l->site_id,
                'tiers' => $l->tiers_comptable_id ? TiersComptable::find($l->tiers_comptable_id)?->tiersable_id : null,
            ]])
            ->all();
    }

    private function soldeCompte(string $numero, ?string $siteId = null): float
    {
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', $numero)->firstOrFail();

        return round((float) EcritureComptable::where('compte_comptable_id', $compte->id)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
            ->value('solde'), 2);
    }

    // ── Même agence : inchangé ───────────────────────────────────────────────

    public function test_un_encaissement_dans_l_agence_de_la_commande_garde_une_seule_piece_client(): void
    {
        $facture = $this->facture();

        $this->encaisser($facture, $this->user, [
            'compte_tresorerie_id' => $this->orangeA->id,
            'site_encaissement_id' => null,
        ])->assertSessionHasNoErrors();

        $encaissement = EncaissementVente::firstOrFail();
        $this->assertSame($this->agenceA->id, $encaissement->site_encaissement_id);
        $this->assertFalse($encaissement->estPourAutreAgence());

        $lignes = $this->lignes($this->piece($encaissement, EvenementComptable::ENCAISSEMENT_VENTE_RECU));
        $this->assertSame(100_000.0, $lignes['561100']['debit']);
        $this->assertSame(100_000.0, $lignes['411000']['credit']);
        $this->assertSame($this->agenceA->id, $lignes['411000']['site_id']);
        $this->assertArrayNotHasKey('181000', $lignes);
        $this->assertNull($this->piece($encaissement, EvenementComptable::ENCAISSEMENT_VENTE_POUR_COMPTE));
    }

    public function test_sans_agence_demandee_l_encaissement_reste_a_l_agence_de_la_facture(): void
    {
        // Comportement de tous les écrans existants : aucune agence envoyée.
        $this->encaisser($this->facture(), $this->agentB, [
            'compte_tresorerie_id' => $this->orangeA->id,
            'site_encaissement_id' => null,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->agenceA->id, EncaissementVente::firstOrFail()->site_encaissement_id);
    }

    public function test_l_agence_de_la_facture_n_est_jamais_remplacee_par_un_encaissement_cree_sans_agence(): void
    {
        $facture = $this->facture();
        $encaissement = EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => 10_000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'virement',
            'reference_paiement' => 'VIR-1',
        ]);

        $this->assertSame($this->agenceA->id, $encaissement->site_encaissement_id);
    }

    // ── Autre agence : trésorerie chez B, client et vente chez A ─────────────

    public function test_la_tresorerie_entre_chez_l_agence_qui_encaisse_et_le_client_reste_a_l_agence_de_la_commande(): void
    {
        $facture = $this->facture();

        $this->encaisser($facture, $this->agentB)->assertSessionHasNoErrors();

        $encaissement = EncaissementVente::firstOrFail();
        $this->assertSame($this->agenceB->id, $encaissement->site_encaissement_id);
        $this->assertTrue($encaissement->estPourAutreAgence());
        $this->assertSame('partiel', $facture->fresh()->statut_facture->value);

        // Pièce 1 — site B : débit trésorerie B / crédit liaison [A].
        $recu = $this->lignes($this->piece($encaissement, EvenementComptable::ENCAISSEMENT_VENTE_RECU));
        $this->assertSame(100_000.0, $recu['561100']['debit']);
        $this->assertSame($this->agenceB->id, $recu['561100']['site_id']);
        $this->assertSame(100_000.0, $recu['181000']['credit']);
        $this->assertSame($this->agenceA->id, $recu['181000']['tiers']);
        $this->assertArrayNotHasKey('411000', $recu, 'le client n\'est jamais crédité chez l\'agence qui encaisse');

        // Pièce 2 — site A : débit liaison [B] / crédit client.
        $pourCompte = $this->lignes($this->piece($encaissement, EvenementComptable::ENCAISSEMENT_VENTE_POUR_COMPTE));
        $this->assertSame(100_000.0, $pourCompte['181000']['debit']);
        $this->assertSame($this->agenceB->id, $pourCompte['181000']['tiers']);
        $this->assertSame($this->agenceA->id, $pourCompte['181000']['site_id']);
        $this->assertSame(100_000.0, $pourCompte['411000']['credit']);
        $this->assertSame($this->agenceA->id, $pourCompte['411000']['site_id']);

        $disponibilite = app(TresorerieDisponibiliteService::class);
        $this->assertSame(100_000.0, $disponibilite->soldePourSupport($this->orangeB));
        $this->assertSame(0.0, $disponibilite->soldePourSupport($this->orangeA));

        $this->assertSame(0.0, $this->soldeCompte('181000'), 'la liaison se solde à 0 au niveau de l\'organisation');
        $this->assertSame(-100_000.0, $this->soldeCompte('181000', $this->agenceB->id), 'B doit 100 000 à A');
        $this->assertSame(100_000.0, $this->soldeCompte('181000', $this->agenceA->id), 'A doit recevoir 100 000 de B');
    }

    public function test_les_especes_vont_dans_la_caisse_dediee_de_l_agent_sur_l_agence_d_encaissement(): void
    {
        $caisseB = $this->creerCaisseActivePour($this->agentB, $this->agenceB->id);

        $this->encaisser($this->facture(), $this->agentB, [
            'mode_paiement' => 'especes',
            'compte_tresorerie_id' => null,
            'reference_paiement' => null,
        ])->assertSessionHasNoErrors();

        $this->assertSame(100_000.0, app(TresorerieDisponibiliteService::class)->soldePourSupport($caisseB));
    }

    public function test_les_especes_sans_caisse_sur_l_agence_d_encaissement_sont_refusees(): void
    {
        // Une caisse sur l'agence de la commande ne suffit pas : l'argent est reçu à B.
        $this->agentB->sites()->attach($this->agenceA->id, ['role' => 'employe', 'is_default' => false]);
        $this->creerCaisseActivePour($this->agentB, $this->agenceA->id);

        $this->encaisser($this->facture(), $this->agentB, [
            'mode_paiement' => 'especes',
            'compte_tresorerie_id' => null,
            'reference_paiement' => null,
        ])->assertSessionHasErrors('mode_paiement');

        $this->assertSame(0, EncaissementVente::count());
    }

    public function test_un_paiement_partiel_ne_cree_une_dette_que_pour_la_part_encaissee_ailleurs(): void
    {
        $facture = $this->facture(montant: 500_000);

        $this->encaisser($facture, $this->user, [
            'montant' => 200_000,
            'compte_tresorerie_id' => $this->orangeA->id,
            'site_encaissement_id' => $this->agenceA->id,
        ])->assertSessionHasNoErrors();
        $this->encaisser($facture, $this->agentB, ['montant' => 300_000, 'reference_paiement' => 'OM-REF-2'])->assertSessionHasNoErrors();

        $this->assertSame('payee', $facture->fresh()->statut_facture->value);

        $soldes = app(DetteInterAgencesService::class)->soldes($this->org->id);
        $this->assertCount(1, $soldes);
        $this->assertSame($this->agenceB->id, $soldes[0]['site_debiteur_id']);
        $this->assertSame($this->agenceA->id, $soldes[0]['site_creancier_id']);
        $this->assertSame(300_000.0, $soldes[0]['a_verser']);
        $this->assertSame(300_000.0, $soldes[0]['a_recevoir']);
        $this->assertSame(300_000.0, app(DetteInterAgencesService::class)->aVerserParSite($this->org->id, $this->agenceB->id));
        $this->assertSame(0.0, app(DetteInterAgencesService::class)->aVerserParSite($this->org->id, $this->agenceA->id));
    }

    // ── Refus ────────────────────────────────────────────────────────────────

    public function test_sans_la_permission_dediee_une_autre_agence_est_refusee(): void
    {
        $agent = $this->creerUtilisateurNonAdmin($this->agenceB, ['factures.encaisser'], 'Sans', 'Permission');

        $this->encaisser($this->facture(), $agent)
            ->assertSessionHasErrors(['site_encaissement_id' => AgenceEncaissementResolver::MESSAGE_SANS_PERMISSION]);

        $this->assertSame(0, EncaissementVente::count());
    }

    public function test_une_agence_a_laquelle_l_utilisateur_n_est_pas_affecte_est_refusee(): void
    {
        $agenceC = $this->creerSite('Agence Labé');
        $this->creerSupportAgence($agenceC->id, 'mobile_money', '561100', 'orange_money');

        $this->encaisser($this->facture(), $this->agentB, ['site_encaissement_id' => $agenceC->id])
            ->assertSessionHasErrors(['site_encaissement_id' => AgenceEncaissementResolver::MESSAGE_NON_AFFECTE]);

        $this->assertSame(0, EncaissementVente::count());
    }

    public function test_l_administrateur_n_a_aucun_passe_droit_d_affectation(): void
    {
        $this->user->givePermissionTo(AgenceEncaissementResolver::PERMISSION);

        $this->encaisser($this->facture(), $this->user)
            ->assertSessionHasErrors(['site_encaissement_id' => AgenceEncaissementResolver::MESSAGE_NON_AFFECTE]);
    }

    public function test_un_support_de_l_agence_de_la_commande_est_refuse_quand_on_encaisse_ailleurs(): void
    {
        $this->encaisser($this->facture(), $this->agentB, ['compte_tresorerie_id' => $this->orangeA->id])
            ->assertSessionHasErrors('compte_tresorerie_id');

        $this->assertSame(0, EncaissementVente::count());
    }

    public function test_une_agence_d_une_autre_organisation_est_refusee(): void
    {
        $autreOrg = Organization::factory()->create();
        $siteEtranger = $this->creerSite('Agence étrangère', $autreOrg);
        $this->agentB->sites()->attach($siteEtranger->id, ['role' => 'employe', 'is_default' => false]);

        $this->encaisser($this->facture(), $this->agentB, ['site_encaissement_id' => $siteEtranger->id])
            ->assertSessionHasErrors(['site_encaissement_id' => AgenceEncaissementResolver::MESSAGE_NON_AFFECTE]);
    }

    // ── Suppression ──────────────────────────────────────────────────────────

    public function test_supprimer_un_encaissement_non_regle_contrepasse_ses_deux_pieces_et_efface_la_dette(): void
    {
        $this->encaisser($this->facture(), $this->agentB)->assertSessionHasNoErrors();
        $encaissement = EncaissementVente::firstOrFail();

        $this->actingAs($this->user)->delete(route('encaissements.destroy', $encaissement))->assertSessionHasNoErrors();

        $this->assertSame('contrepassee', $this->piece($encaissement, EvenementComptable::ENCAISSEMENT_VENTE_RECU)->statut->value);
        $this->assertSame('contrepassee', $this->piece($encaissement, EvenementComptable::ENCAISSEMENT_VENTE_POUR_COMPTE)->statut->value);
        $this->assertSame(0.0, $this->soldeCompte('181000', $this->agenceB->id));
        $this->assertSame(0.0, $this->soldeCompte('181000', $this->agenceA->id));
        $this->assertSame(0.0, app(TresorerieDisponibiliteService::class)->soldePourSupport($this->orangeB));
        $this->assertCount(0, app(DetteInterAgencesService::class)->soldes($this->org->id));
    }

    // ── Recherche « commande d'une autre agence » ────────────────────────────

    public function test_la_recherche_exige_la_permission_dediee(): void
    {
        $agent = $this->creerUtilisateurNonAdmin($this->agenceB, ['factures.encaisser'], 'Sans', 'Permission');
        $facture = $this->facture();

        $this->actingAs($agent)->getJson(route('factures.autre-agence', ['reference' => $facture->reference]))
            ->assertForbidden();
    }

    public function test_la_recherche_propose_les_agences_de_l_utilisateur_avec_leurs_moyens(): void
    {
        $facture = $this->facture();

        $reponse = $this->actingAs($this->agentB)
            ->getJson(route('factures.autre-agence', ['reference' => strtolower($facture->reference)]))
            ->assertOk();

        $reponse->assertJsonPath('facture.id', $facture->id)
            ->assertJsonPath('facture.site_id', $this->agenceA->id)
            ->assertJsonPath('facture.montant_restant', 500000)
            ->assertJsonPath('raison_non_encaissable', null)
            ->assertJsonPath('agence_defaut', $this->agenceB->id)
            ->assertJsonCount(1, 'agences')
            ->assertJsonPath('agences.0.site_id', $this->agenceB->id)
            ->assertJsonPath('agences.0.peut_encaisser_especes', false)
            ->assertJsonPath('agences.0.moyens.0.compte_tresorerie_id', $this->orangeB->id);
    }

    public function test_l_agence_de_la_commande_est_proposee_par_defaut_quand_l_utilisateur_y_est_affecte(): void
    {
        $this->agentB->sites()->attach($this->agenceA->id, ['role' => 'employe', 'is_default' => false]);
        $facture = $this->facture();

        $this->actingAs($this->agentB)
            ->getJson(route('factures.autre-agence', ['reference' => $facture->reference]))
            ->assertOk()
            ->assertJsonPath('agence_defaut', $this->agenceA->id)
            ->assertJsonCount(2, 'agences')
            ->assertJsonPath('agences.0.site_id', $this->agenceA->id);
    }

    public function test_la_recherche_ne_trouve_jamais_une_facture_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $factureEtrangere = $this->facture($this->creerSite('Ailleurs', $autreOrg));

        $this->actingAs($this->agentB)
            ->getJson(route('factures.autre-agence', ['reference' => $factureEtrangere->reference]))
            ->assertNotFound();
    }

    // ── Migrations ───────────────────────────────────────────────────────────

    public function test_la_reprise_donne_a_chaque_encaissement_existant_l_agence_de_sa_facture(): void
    {
        $this->encaisser($this->facture(), $this->user, [
            'compte_tresorerie_id' => $this->orangeA->id,
            'site_encaissement_id' => null,
        ])->assertSessionHasNoErrors();
        $encaissement = EncaissementVente::firstOrFail();
        DB::table('encaissements_ventes')->update(['site_encaissement_id' => null]);

        $migration = require database_path('migrations/2026_09_29_100000_add_site_encaissement_id_to_encaissements_ventes_table.php');
        $migration->up();
        $migration->up();

        $this->assertSame($this->agenceA->id, $encaissement->fresh()->site_encaissement_id);
    }

    public function test_la_reprise_accorde_la_permission_au_seul_admin_entreprise(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $migration = require database_path('migrations/2026_09_29_100300_backfill_factures_encaisser_autre_agence_permission.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Role::findByName('admin_entreprise')->hasPermissionTo(AgenceEncaissementResolver::PERMISSION));
        $this->assertFalse(Role::findByName('manager')->hasPermissionTo(AgenceEncaissementResolver::PERMISSION));
    }

    public function test_la_permission_est_au_catalogue(): void
    {
        $this->assertArrayHasKey(AgenceEncaissementResolver::PERMISSION, PermissionCatalog::STANDALONE);
        $this->assertContains(AgenceEncaissementResolver::PERMISSION, PermissionCatalog::DOMAINS['ventes']['standalone']['Facturation']);
    }
}
