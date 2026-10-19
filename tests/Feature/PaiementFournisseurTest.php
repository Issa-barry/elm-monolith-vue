<?php

namespace Tests\Feature;

use App\Enums\EvenementComptable;
use App\Enums\StatutCommandeAchat;
use App\Enums\StatutFactureFournisseur;
use App\Features\ModuleFeature;
use App\Models\CommandeAchat;
use App\Models\CompteComptable;
use App\Models\CompteMapping;
use App\Models\CompteTresorerie;
use App\Models\EcritureComptable;
use App\Models\EntrepriseTierce;
use App\Models\FactureFournisseur;
use App\Models\Fournisseur;
use App\Models\JournalComptable;
use App\Models\Organization;
use App\Models\PaiementFournisseur;
use App\Models\PieceComptable;
use App\Models\ReceptionAchat;
use App\Models\RegleValidationRole;
use App\Models\Site;
use App\Models\User;
use App\Services\Comptabilite\FactureFournisseurComptabilisationService;
use App\Services\Tresorerie\ObligationsAgenceService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Paiement des factures fournisseurs (ADR 0024) : décaissement réel depuis un support de l'agence
 * de la facture — ou d'une autre agence du périmètre du payeur (révision du 12/10/2026) —, partiel
 * ou total, reste dû relu sous verrou, solde garanti, écriture 401 / support indissociable du
 * paiement, dette intégrée à l'argent que l'agence de la facture conserve (ADR 0016).
 */
class PaiementFournisseurTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $agence;

    private Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['achats.read', 'factures-fournisseurs.read', 'factures-fournisseurs.payer']);
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
        $this->agence = $this->user->sites()->wherePivot('is_default', true)->firstOrFail();
        RegleValidationRole::create([
            'organization_id' => $this->org->id, 'domaine' => RegleValidationRole::DOMAINE_ACHATS,
            'role_name' => 'admin_entreprise', 'perimetre' => 'toutes_agences',
        ]);
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'FOURNISSEUR PAYÉ']);
        $this->fournisseur = Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'is_active' => true]);
    }

    /** Facture d'achat ; constatée, son écriture de validation est passée sauf si $comptabiliser est faux. */
    private function facture(float $ttc = 100_000, StatutFactureFournisseur $statut = StatutFactureFournisseur::VALIDEE, ?Site $site = null, ?string $echeance = null, bool $comptabiliser = true): FactureFournisseur
    {
        $site ??= $this->agence;
        $commande = CommandeAchat::create([
            'organization_id' => $this->org->id, 'site_id' => $site->id, 'fournisseur_id' => $this->fournisseur->id,
            'total_commande' => $ttc, 'statut' => StatutCommandeAchat::RECEPTIONNEE, 'validee_at' => now(),
        ]);

        $facture = FactureFournisseur::create([
            'organization_id' => $this->org->id, 'commande_achat_id' => $commande->id, 'fournisseur_id' => $this->fournisseur->id,
            'site_id' => $site->id, 'reference' => 'FAF-PAY-'.Str::upper(Str::random(5)), 'numero_facture_fournisseur' => 'N-'.Str::random(6),
            'date_facture' => now()->toDateString(), 'date_echeance' => $echeance,
            'montant_ht' => $ttc, 'montant_ttc' => $ttc, 'statut' => $statut, 'validee_at' => now(),
        ]);

        // Une ligne facturée (bon → réception → facture) : l'écriture de validation débite les lignes.
        $ligneCommande = $commande->lignes()->create(['qte' => 1, 'qte_recue' => 1, 'prix_achat_snapshot' => $ttc, 'total_ligne' => $ttc, 'libelle_snapshot' => 'Article test']);
        $reception = ReceptionAchat::create([
            'organization_id' => $this->org->id, 'commande_achat_id' => $commande->id, 'site_id' => $site->id,
            'reference' => 'RCA-PAY-'.Str::upper(Str::random(6)), 'date_reception' => now()->toDateString(),
        ]);
        $ligneRecue = $reception->lignes()->create(['commande_achat_ligne_id' => $ligneCommande->id, 'qte_recue' => 1, 'cout_unitaire' => $ttc]);
        $facture->lignes()->create([
            'reception_achat_ligne_id' => $ligneRecue->id, 'commande_achat_ligne_id' => $ligneCommande->id,
            'libelle_snapshot' => 'Article test', 'qte_facturee' => 1, 'prix_unitaire' => $ttc, 'total_ht' => $ttc,
        ]);

        if ($comptabiliser && $facture->isConstatee()) {
            $this->mapperCompteAchat();
            app(FactureFournisseurComptabilisationService::class)->comptabiliserFactureValidee($facture);
        }

        return $facture;
    }

    private function mapperCompteAchat(): void
    {
        $compte = CompteComptable::firstOrCreate(['organization_id' => $this->org->id, 'numero' => '601000'], ['libelle' => 'Achats (test)', 'actif' => true]);
        CompteMapping::firstOrCreate(
            ['organization_id' => $this->org->id, 'evenement' => EvenementComptable::FACTURE_FOURNISSEUR_VALIDEE->value, 'role' => 'achat'],
            [
                'compte_comptable_id' => $compte->id,
                'journal_comptable_id' => JournalComptable::where('organization_id', $this->org->id)->where('code', 'AC')->value('id'),
                'actif' => true,
            ],
        );
    }

    private function payer(FactureFournisseur $facture, array $donnees, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)->post(route('achats.factures.paiements.store', $facture), array_merge([
            'date_paiement' => now()->toDateString(),
        ], $donnees));
    }

    private function solde(CompteTresorerie $support): float
    {
        return app(TresorerieDisponibiliteService::class)->soldePourSupport($support->fresh(), now());
    }

    // ── Paiement partiel / total ──────────────────────────────────────────────

    public function test_paiement_partiel_en_especes_debite_la_caisse_et_passe_l_ecriture_401(): void
    {
        $caisse = $this->equiperPayeurEspeces($this->user, $this->agence->id, 500_000);
        $facture = $this->facture(100_000);

        $this->payer($facture, ['montant' => 40_000, 'mode_paiement' => 'especes'])->assertSessionHasNoErrors();

        $facture->refresh();
        $this->assertSame(StatutFactureFournisseur::PARTIELLEMENT_PAYEE, $facture->statut);
        $this->assertSame(40_000.0, (float) $facture->montant_paye);
        $this->assertSame(60_000.0, $facture->resteDu());
        $this->assertSame(460_000.0, $this->solde($caisse));

        $paiement = PaiementFournisseur::firstOrFail();
        $this->assertSame($caisse->id, $paiement->compte_tresorerie_id);
        $this->assertSame($this->agence->id, $paiement->site_id);

        $piece = PieceComptable::where('source_id', $paiement->id)->where('type_evenement', EvenementComptable::PAIEMENT_FOURNISSEUR->value)->firstOrFail();
        $lignes = EcritureComptable::where('piece_comptable_id', $piece->id)->get();
        $debit = $lignes->firstWhere('debit', '>', 0);
        $credit = $lignes->firstWhere('credit', '>', 0);
        $this->assertSame('401000', CompteComptable::find($debit->compte_comptable_id)->numero);
        $this->assertNotNull($debit->tiers_comptable_id);
        $this->assertSame($caisse->compte_comptable_id, $credit->compte_comptable_id);
        $this->assertSame(40_000.0, (float) $credit->credit);
    }

    public function test_paiement_bloque_tant_que_l_ecriture_de_la_facture_est_en_attente(): void
    {
        $caisse = $this->equiperPayeurEspeces($this->user, $this->agence->id, 500_000);
        $facture = $this->facture(100_000, comptabiliser: false);
        $motif = "Paiement impossible : l'écriture comptable de cette facture est en attente (compte d'achat ou de TVA non paramétré). Faites paramétrer les comptes, relancez la comptabilisation de la facture, puis payez-la.";

        // Fiche : pas de bouton Payer, motif affiché.
        $this->actingAs($this->user)->get(route('achats.factures.show', $facture))
            ->assertInertia(fn ($page) => $page->where('actions.peut_payer', false)->where('actions.motif_non_payable', $motif));

        // Appel direct : refus serveur, sans aucun effet.
        $this->payer($facture, ['montant' => 40_000, 'mode_paiement' => 'especes'])->assertSessionHasErrors(['paiement' => $motif]);
        $this->assertSame(0, PaiementFournisseur::count());
        $this->assertSame(0.0, (float) $facture->fresh()->montant_paye);
        $this->assertSame(StatutFactureFournisseur::VALIDEE, $facture->fresh()->statut);
        $this->assertSame(500_000.0, $this->solde($caisse));
        $this->assertFalse(PieceComptable::where('type_evenement', EvenementComptable::PAIEMENT_FOURNISSEUR->value)->exists());

        // Rattrapage comptable (compte d'achat paramétré, écriture passée) : paiement accepté.
        $this->mapperCompteAchat();
        app(FactureFournisseurComptabilisationService::class)->comptabiliserFactureValidee($facture);
        $this->payer($facture, ['montant' => 40_000, 'mode_paiement' => 'especes'])->assertSessionHasNoErrors();
        $this->assertSame(40_000.0, (float) $facture->fresh()->montant_paye);
        $this->assertSame(460_000.0, $this->solde($caisse));
    }

    public function test_plusieurs_paiements_jusqu_a_payee_et_jamais_au_dela_du_reste_du(): void
    {
        $this->equiperPayeurEspeces($this->user, $this->agence->id, 500_000);
        $facture = $this->facture(100_000);

        $this->payer($facture, ['montant' => 70_000, 'mode_paiement' => 'especes'])->assertSessionHasNoErrors();
        $this->payer($facture, ['montant' => 30_001, 'mode_paiement' => 'especes'])->assertSessionHasErrors('montant');
        $this->payer($facture, ['montant' => 30_000, 'mode_paiement' => 'especes'])->assertSessionHasNoErrors();

        $facture->refresh();
        $this->assertSame(StatutFactureFournisseur::PAYEE, $facture->statut);
        $this->assertSame(0.0, $facture->resteDu());
        $this->assertSame(2, $facture->paiements()->count());
        $this->payer($facture, ['montant' => 1, 'mode_paiement' => 'especes'])->assertSessionHasErrors('paiement');
    }

    public function test_mobile_money_debite_le_compte_reel_de_l_agence(): void
    {
        $orange = $this->creerSupportAgence($this->agence->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($orange, 1_000_000);
        $facture = $this->facture(300_000);

        $this->payer($facture, [
            'montant' => 300_000, 'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $orange->id, 'reference_paiement' => 'OM-FOURN-1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(700_000.0, $this->solde($orange));
        $this->assertSame(StatutFactureFournisseur::PAYEE, $facture->fresh()->statut);
    }

    // ── Refus sans effet ──────────────────────────────────────────────────────

    public function test_solde_insuffisant_refuse_sans_aucun_effet(): void
    {
        $caisse = $this->equiperPayeurEspeces($this->user, $this->agence->id, 10_000);
        $facture = $this->facture(100_000);

        $this->payer($facture, ['montant' => 50_000, 'mode_paiement' => 'especes'])->assertSessionHasErrors();

        $this->assertSame(0, PaiementFournisseur::count());
        $this->assertSame(0.0, (float) $facture->fresh()->montant_paye);
        $this->assertSame(StatutFactureFournisseur::VALIDEE, $facture->fresh()->statut);
        $this->assertSame(10_000.0, $this->solde($caisse));
        $this->assertSame(0, PieceComptable::where('type_evenement', EvenementComptable::PAIEMENT_FOURNISSEUR->value)->count());
    }

    public function test_especes_sans_caisse_dediee_refusees(): void
    {
        $facture = $this->facture(100_000);

        $this->payer($facture, ['montant' => 10_000, 'mode_paiement' => 'especes'])->assertSessionHasErrors('mode_paiement');
        $this->assertSame(0, PaiementFournisseur::count());
    }

    public function test_seule_une_facture_validee_peut_etre_payee(): void
    {
        $this->equiperPayeurEspeces($this->user, $this->agence->id, 500_000);

        foreach ([StatutFactureFournisseur::BROUILLON, StatutFactureFournisseur::ANNULEE] as $statut) {
            $this->payer($this->facture(100_000, $statut), ['montant' => 10_000, 'mode_paiement' => 'especes'])
                ->assertSessionHasErrors(['paiement' => 'Seule une facture validée et non soldée peut être payée.']);
        }
        $this->assertSame(0, PaiementFournisseur::count());
    }

    public function test_une_facture_partiellement_payee_ne_peut_plus_etre_annulee(): void
    {
        $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'factures-fournisseurs.annuler', 'guard_name' => 'web']));
        $this->equiperPayeurEspeces($this->user, $this->agence->id, 500_000);
        $facture = $this->facture(100_000);
        $this->payer($facture, ['montant' => 10_000, 'mode_paiement' => 'especes'])->assertSessionHasNoErrors();

        $this->actingAs($this->user)
            ->patch(route('achats.factures.annuler', $facture), ['motif_annulation' => 'Erreur'])
            ->assertSessionHasErrors('motif_annulation');
        $this->assertSame(StatutFactureFournisseur::PARTIELLEMENT_PAYEE, $facture->fresh()->statut);
    }

    // ── Permission et périmètre ───────────────────────────────────────────────

    public function test_refus_sans_permission_et_hors_perimetre(): void
    {
        $facture = $this->facture(100_000);

        $sansPermission = $this->makeUserWithPermissions($this->org, ['achats.read', 'factures-fournisseurs.read']);
        $sansPermission->sites()->attach($this->agence->id, ['role' => 'employe', 'is_default' => true]);
        $this->payer($facture, ['montant' => 1_000, 'mode_paiement' => 'especes'], $sansPermission)->assertForbidden();

        $autreAgence = Site::factory()->for($this->org)->create();
        $role = Role::firstOrCreate(['name' => 'tresorier_agence', 'guard_name' => 'web']);
        $role->givePermissionTo(['factures-fournisseurs.read', 'factures-fournisseurs.payer']);
        RegleValidationRole::create([
            'organization_id' => $this->org->id, 'domaine' => RegleValidationRole::DOMAINE_ACHATS,
            'role_name' => 'tresorier_agence', 'perimetre' => 'agences_selectionnees', 'sites' => [$autreAgence->id],
        ]);
        $horsPerimetre = User::factory()->create(['organization_id' => $this->org->id]);
        $horsPerimetre->assignRole($role);
        $horsPerimetre->sites()->attach($this->agence->id, ['role' => 'employe', 'is_default' => true]);
        $this->payer($facture, ['montant' => 1_000, 'mode_paiement' => 'especes'], $horsPerimetre)->assertForbidden();

        $this->assertSame(0, PaiementFournisseur::count());
    }

    public function test_le_super_administrateur_sans_regle_ne_paie_pas(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->agence->id, ['role' => 'employe', 'is_default' => true]);
        $this->equiperPayeurEspeces($superAdmin, $this->agence->id, 500_000);
        $facture = $this->facture(100_000);

        $this->payer($facture, ['montant' => 10_000, 'mode_paiement' => 'especes'], $superAdmin)->assertForbidden();

        RegleValidationRole::provisionnerAchatsParDefaut($this->org->id);
        $this->payer($facture, ['montant' => 10_000, 'mode_paiement' => 'especes'], $superAdmin)->assertSessionHasNoErrors();
        $this->assertSame(10_000.0, (float) $facture->fresh()->montant_paye);
    }

    // ── Paiement depuis une autre agence que celle de la facture (révision du 12/10/2026) ──

    /** Agence de l'organisation avec un compte bancaire approvisionné (virement et chèque). */
    private function agenceAvecBanque(string $nom, float $solde = 1_000_000): array
    {
        $site = Site::create(['organization_id' => $this->org->id, 'nom' => $nom, 'type' => 'depot', 'localisation' => 'Conakry']);
        $banque = $this->creerSupportAgence($site->id, 'banque', '521000', null, 'UBA '.$nom);
        $this->alimenterCaisse($banque, $solde);

        return [$site, $banque];
    }

    /** Payeur dont le périmètre « Peut acheter pour » se limite aux agences données. */
    private function payeurLimiteA(array $sites): User
    {
        $role = Role::firstOrCreate(['name' => 'tresorier_agence', 'guard_name' => 'web']);
        $role->givePermissionTo(['achats.read', 'factures-fournisseurs.read', 'factures-fournisseurs.payer']);
        RegleValidationRole::create([
            'organization_id' => $this->org->id, 'domaine' => RegleValidationRole::DOMAINE_ACHATS,
            'role_name' => 'tresorier_agence', 'perimetre' => 'agences_selectionnees', 'sites' => array_map(fn (Site $s) => $s->id, $sites),
        ]);
        $payeur = User::factory()->create(['organization_id' => $this->org->id]);
        $payeur->assignRole($role);
        $payeur->sites()->attach($sites[0]->id, ['role' => 'employe', 'is_default' => true]);

        return $payeur;
    }

    public function test_une_autre_agence_paie_par_virement_puis_par_cheque_et_la_dette_reste_unique(): void
    {
        [$matoto, $banque] = $this->agenceAvecBanque('Matoto');
        $facture = $this->facture(300_000, echeance: now()->startOfMonth()->addDays(20)->toDateString());

        $this->payer($facture, [
            'montant' => 200_000, 'mode_paiement' => 'virement', 'compte_tresorerie_id' => $banque->id,
            'reference_paiement' => 'VIR-MATOTO-1', 'site_paiement_id' => $matoto->id,
        ])->assertSessionHasNoErrors();

        // Une seule dette, portée par la facture et son agence ; le paiement garde l'agence réelle.
        $facture->refresh();
        $this->assertSame($this->agence->id, $facture->site_id);
        $this->assertSame(StatutFactureFournisseur::PARTIELLEMENT_PAYEE, $facture->statut);
        $this->assertSame(100_000.0, $facture->resteDu());
        $paiement = PaiementFournisseur::firstOrFail();
        $this->assertSame($matoto->id, $paiement->site_id);
        $this->assertSame($banque->id, $paiement->compte_tresorerie_id);
        $this->assertSame(800_000.0, $this->solde($banque));

        // Écriture : 401 débité, compte de la banque de Matoto crédité, rattachée à Matoto.
        $piece = PieceComptable::where('source_id', $paiement->id)->where('type_evenement', EvenementComptable::PAIEMENT_FOURNISSEUR->value)->firstOrFail();
        $lignes = EcritureComptable::where('piece_comptable_id', $piece->id)->get();
        $this->assertSame('401000', CompteComptable::find($lignes->firstWhere('debit', '>', 0)->compte_comptable_id)->numero);
        $this->assertSame($banque->compte_comptable_id, $lignes->firstWhere('credit', '>', 0)->compte_comptable_id);
        $this->assertSame([$matoto->id], $lignes->pluck('site_id')->unique()->values()->all());

        // Financement des agences : le reste dû n'est compté qu'une fois, chez l'agence de la facture.
        $obligations = collect(app(ObligationsAgenceService::class)->calculerPourMois($this->org->id, now()->year, now()->month));
        $this->assertSame(100_000.0, (float) $obligations->firstWhere('site_id', $this->agence->id)['fournisseurs']);
        $this->assertSame(0.0, (float) ($obligations->firstWhere('site_id', $matoto->id)['fournisseurs'] ?? 0));

        // La fiche nomme l'agence qui a réellement payé.
        $this->actingAs($this->user)->get(route('achats.factures.show', $facture))
            ->assertInertia(fn ($page) => $page->where('paiements.0.autre_agence_nom', 'Matoto'));

        // Solde par chèque de la même agence (sans référence) : facture payée, toujours une seule dette.
        $this->payer($facture, [
            'montant' => 100_000, 'mode_paiement' => 'cheque', 'compte_tresorerie_id' => $banque->id, 'site_paiement_id' => $matoto->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(StatutFactureFournisseur::PAYEE, $facture->fresh()->statut);
        $this->assertSame(700_000.0, $this->solde($banque));
        $this->assertSame(2, PaiementFournisseur::where('site_id', $matoto->id)->count());
    }

    public function test_paiement_par_banque_sans_reference_mobile_money_avec_reference(): void
    {
        // Décision du 17/10/2026 : aucune référence pour un paiement par banque ; le Mobile Money la garde.
        $banque = $this->creerSupportAgence($this->agence->id, 'banque', '521000', null, 'UBA');
        $this->alimenterCaisse($banque, 1_000_000);
        $facture = $this->facture(300_000);

        $this->actingAs($this->user)->get(route('achats.factures.show', $facture))
            ->assertInertia(fn ($page) => $page->where('paiement.moyens', fn ($moyens) => collect($moyens)->every(fn ($m) => $m['reference_requise'] === false)));

        $this->payer($facture, ['montant' => 100_000, 'mode_paiement' => 'virement', 'compte_tresorerie_id' => $banque->id])->assertSessionHasNoErrors();
        $this->payer($facture, ['montant' => 100_000, 'mode_paiement' => 'cheque', 'compte_tresorerie_id' => $banque->id])->assertSessionHasNoErrors();
        $this->assertSame(2, PaiementFournisseur::whereNull('reference_paiement')->count());
        $this->assertSame(800_000.0, $this->solde($banque));

        $orange = $this->creerSupportAgence($this->agence->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($orange, 500_000);
        $this->payer($facture, ['montant' => 50_000, 'mode_paiement' => 'mobile_money', 'compte_tresorerie_id' => $orange->id])
            ->assertSessionHasErrors('reference_paiement');
    }

    public function test_le_support_d_une_autre_agence_est_refuse_si_elle_n_est_pas_choisie(): void
    {
        [, $banque] = $this->agenceAvecBanque('Matoto');
        $facture = $this->facture(100_000);

        // Sans agence choisie, l'argent sort de l'agence de la facture : la banque de Matoto n'en est pas.
        $this->payer($facture, [
            'montant' => 50_000, 'mode_paiement' => 'virement', 'compte_tresorerie_id' => $banque->id, 'reference_paiement' => 'VIR-X',
        ])->assertSessionHasErrors('compte_tresorerie_id');

        $this->assertSame(0, PaiementFournisseur::count());
        $this->assertSame(1_000_000.0, $this->solde($banque));
        $this->assertSame(0.0, (float) $facture->fresh()->montant_paye);
    }

    public function test_refus_de_payer_depuis_une_agence_hors_perimetre_ou_d_une_autre_organisation(): void
    {
        [$matoto, $banque] = $this->agenceAvecBanque('Matoto');
        $facture = $this->facture(100_000);
        $message = "Vous ne pouvez pas payer depuis cette agence : elle n'est pas dans votre périmètre d'achat.";

        // Périmètre limité à l'agence de la facture : la trésorerie de Matoto reste hors d'atteinte,
        // même en nommant son support.
        $limite = $this->payeurLimiteA([$this->agence]);
        $this->payer($facture, [
            'montant' => 50_000, 'mode_paiement' => 'virement', 'compte_tresorerie_id' => $banque->id,
            'reference_paiement' => 'VIR-X', 'site_paiement_id' => $matoto->id,
        ], $limite)->assertSessionHasErrors(['site_paiement_id' => $message]);

        // Périmètre « toutes agences » : jamais une agence d'une autre organisation.
        $etrangere = Site::create(['organization_id' => Organization::factory()->create()->id, 'nom' => 'Ailleurs', 'type' => 'depot', 'localisation' => 'Kindia']);
        $this->payer($facture, ['montant' => 50_000, 'mode_paiement' => 'especes', 'site_paiement_id' => $etrangere->id])
            ->assertSessionHasErrors(['site_paiement_id' => $message]);

        $this->assertSame(0, PaiementFournisseur::count());
        $this->assertSame(1_000_000.0, $this->solde($banque));
        $this->assertSame(StatutFactureFournisseur::VALIDEE, $facture->fresh()->statut);
    }

    public function test_couvrir_l_agence_qui_paie_ne_suffit_pas_il_faut_aussi_celle_de_la_facture(): void
    {
        [$matoto, $banque] = $this->agenceAvecBanque('Matoto');
        $facture = $this->facture(100_000);

        $payeurDeMatoto = $this->payeurLimiteA([$matoto]);
        $this->payer($facture, [
            'montant' => 50_000, 'mode_paiement' => 'virement', 'compte_tresorerie_id' => $banque->id,
            'reference_paiement' => 'VIR-X', 'site_paiement_id' => $matoto->id,
        ], $payeurDeMatoto)->assertForbidden();

        $this->assertSame(0, PaiementFournisseur::count());
    }

    public function test_especes_depuis_une_autre_agence_caisse_dediee_du_payeur_dans_cette_agence(): void
    {
        $cba = Site::create(['organization_id' => $this->org->id, 'nom' => 'Cba', 'type' => 'depot', 'localisation' => 'Conakry']);
        $kindia = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kindia', 'type' => 'depot', 'localisation' => 'Kindia']);
        // L'agent a reçu des espèces dans sa caisse dédiée de Cba ; il n'a aucune caisse ailleurs.
        $this->user->sites()->attach($cba->id, ['role' => 'employe', 'is_default' => false]);
        $caisse = $this->equiperPayeurEspeces($this->user, $cba->id, 200_000);
        $facture = $this->facture(100_000);

        // Pas de caisse dans l'agence de la facture, ni à Kindia : refus, comme partout ailleurs.
        $this->payer($facture, ['montant' => 60_000, 'mode_paiement' => 'especes'])->assertSessionHasErrors('mode_paiement');
        $this->payer($facture, ['montant' => 60_000, 'mode_paiement' => 'especes', 'site_paiement_id' => $kindia->id])->assertSessionHasErrors('mode_paiement');
        $this->assertSame(0, PaiementFournisseur::count());

        // Depuis Cba : l'argent sort de sa caisse dédiée.
        $this->payer($facture, ['montant' => 60_000, 'mode_paiement' => 'especes', 'site_paiement_id' => $cba->id])->assertSessionHasNoErrors();
        $paiement = PaiementFournisseur::firstOrFail();
        $this->assertSame($caisse->id, $paiement->compte_tresorerie_id);
        $this->assertSame($cba->id, $paiement->site_id);
        $this->assertSame(140_000.0, $this->solde($caisse));
        $this->assertSame(40_000.0, $facture->fresh()->resteDu());
    }

    public function test_fiche_propose_les_agences_du_perimetre_ou_un_paiement_est_possible(): void
    {
        [$matoto, $banque] = $this->agenceAvecBanque('Matoto', 750_000);
        Site::create(['organization_id' => $this->org->id, 'nom' => 'Sans moyens', 'type' => 'depot', 'localisation' => 'Boké']);
        $facture = $this->facture(100_000);

        // L'agence de la facture en tête (choix par défaut), puis Matoto ; l'agence sans moyen ni caisse est omise.
        $this->actingAs($this->user)->get(route('achats.factures.show', $facture))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('paiement.agence_facture.id', $this->agence->id)
                ->has('paiement.agences', 2)
                ->where('paiement.agences.0.site_id', $this->agence->id)
                ->where('paiement.agences.0.peut_encaisser_especes', false)
                ->where('paiement.agences.1.site_id', $matoto->id)
                ->where('paiement.agences.1.nom', 'Matoto')
                ->where('paiement.agences.1.moyens.0.compte_tresorerie_id', $banque->id)
                ->where('paiement.agences.1.moyens.0.solde_disponible', 750_000));

        // Périmètre limité à l'agence de la facture : aucune autre agence n'est proposée.
        $this->actingAs($this->payeurLimiteA([$this->agence]))->get(route('achats.factures.show', $facture))
            ->assertInertia(fn ($page) => $page->where('actions.peut_payer', true)->has('paiement.agences', 1));
    }

    // ── Fiche et obligations de l'agence ──────────────────────────────────────

    public function test_fiche_propose_le_paiement_avec_les_moyens_de_l_agence(): void
    {
        $this->equiperPayeurEspeces($this->user, $this->agence->id, 500_000);
        $facture = $this->facture(100_000);

        $this->actingAs($this->user)->get(route('achats.factures.show', $facture))->assertOk()
            ->assertInertia(fn ($page) => $page->where('actions.peut_payer', true)
                ->where('paiement.especes_disponibles', true)
                ->where('paiement.solde_especes', 500_000));

        $this->payer($facture, ['montant' => 100_000, 'mode_paiement' => 'especes']);
        $this->actingAs($this->user)->get(route('achats.factures.show', $facture))
            ->assertInertia(fn ($page) => $page->where('actions.peut_payer', false)->where('paiements.0.montant', 100_000));
    }

    public function test_les_factures_non_payees_entrent_dans_ce_que_l_agence_conserve(): void
    {
        $this->equiperPayeurEspeces($this->user, $this->agence->id, 500_000);
        $duCeMois = $this->facture(100_000, echeance: now()->startOfMonth()->addDays(20)->toDateString());
        $this->facture(50_000, echeance: now()->subMonthNoOverflow()->startOfMonth()->addDays(5)->toDateString());
        $this->facture(30_000, StatutFactureFournisseur::BROUILLON, echeance: now()->toDateString());

        $obligations = app(ObligationsAgenceService::class);
        $ligne = collect($obligations->calculerPourMois($this->org->id, now()->year, now()->month))->firstWhere('site_id', $this->agence->id);
        $this->assertSame(100_000.0, (float) $ligne['fournisseurs']);
        $this->assertSame(50_000.0, (float) ($obligations->arrieresParSite($this->org->id, now()->startOfMonth())[$this->agence->id] ?? 0));

        $this->payer($duCeMois, ['montant' => 40_000, 'mode_paiement' => 'especes'])->assertSessionHasNoErrors();
        $ligne = collect($obligations->calculerPourMois($this->org->id, now()->year, now()->month))->firstWhere('site_id', $this->agence->id);
        $this->assertSame(60_000.0, (float) $ligne['fournisseurs']);
        $this->assertSame(100_000.0, (float) $ligne['fournisseurs_du']);
    }
}
