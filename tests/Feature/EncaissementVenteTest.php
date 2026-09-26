<?php

namespace Tests\Feature;

use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\TestCase;

class EncaissementVenteTest extends TestCase
{
    use HasCaissesDediees, RefreshDatabase;

    private function utilisateur(Organization $org): User
    {
        Permission::firstOrCreate(['name' => 'ventes.update', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'factures.encaisser', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo(['ventes.update', 'factures.encaisser']);

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function creerContexte(): array
    {
        $org = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'total_commande' => 5000,
        ]);
        $facture = FactureVente::factory()->create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
            'montant_net' => 5000,
        ]);
        $user = $this->utilisateur($org);

        // Espèces = caisse dédiée active de l'auteur sur le site de la facture (règle du 23/09/2026 ;
        // le refus est couvert par Tresorerie/EncaissementEspecesCaisseObligatoireTest).
        $siteId = $user->sites()->firstOrFail()->id;
        $facture->update(['site_id' => $siteId]);
        $this->creerCaisseActivePour($user, $siteId);

        return compact('org', 'vehicule', 'commande', 'facture', 'user');
    }

    // ── Encaissement store ────────────────────────────────────────────────────

    public function test_encaissement_change_statut_facture_en_partiel(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 2000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $this->assertEquals(StatutFactureVente::PARTIEL, $facture->fresh()->statut_facture);
    }

    public function test_encaissement_complet_change_statut_facture_en_payee(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 5000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $this->assertEquals(StatutFactureVente::PAYEE, $facture->fresh()->statut_facture);
    }

    public function test_encaissement_partiel_ne_change_pas_statut_en_payee(): void
    {
        ['facture' => $facture, 'commande' => $commande, 'user' => $user] = $this->creerContexte();

        $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 2500,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $this->assertEquals(StatutFactureVente::PARTIEL, $facture->fresh()->statut_facture);
    }

    public function test_encaissement_depasse_restant_est_refuse(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        $response = $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 99999, // dépasse 5000
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $response->assertSessionHasErrors('montant');
    }

    public function test_encaissement_refuse_sur_facture_annulee(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $facture->update(['statut_facture' => StatutFactureVente::ANNULEE]);

        $response = $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $response->assertStatus(422);
    }

    // ── Autorisation : permission dédiée factures.encaisser (2026-09-13) ──────
    // can_encaisser/StoreEncaissementVenteController vérifiaient jusqu'ici ventes.update — la
    // route n'avait alors AUCUN contrôle d'autorisation propre. La permission dédiée
    // factures.encaisser doit désormais être totalement indépendante de ventes.update.

    public function test_encaissement_refuse_sans_permission_dediee(): void
    {
        ['facture' => $facture, 'org' => $org] = $this->creerContexte();

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('ventes.update');

        $response = $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $response->assertStatus(403);
        $this->assertDatabaseMissing('encaissements_ventes', ['facture_vente_id' => $facture->id]);
    }

    public function test_encaissement_autorise_par_sa_seule_permission_dediee(): void
    {
        ['facture' => $facture, 'org' => $org] = $this->creerContexte();

        Permission::firstOrCreate(['name' => 'factures.encaisser', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        // Rôle attaché uniquement pour satisfaire EnsureIsStaffAccount::hasBackofficeAccess()
        // (middleware 'staff') — ce rôle vide n'apporte aucune permission, la capacité
        // d'encaisser vient exclusivement du don direct ci-dessous.
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('factures.encaisser');
        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test 2',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        $facture->update(['site_id' => $site->id]);
        $this->creerCaisseActivePour($user, $site->id);

        $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        )->assertRedirect();

        $this->assertDatabaseHas('encaissements_ventes', [
            'facture_vente_id' => $facture->id,
            'montant' => 1000,
        ]);
    }

    // ── Moyens hors espèces : un support actif de l'agence (décision du 24/09/2026) ──────────
    // mode_paiement reste l'une des 4 valeurs stables attendues par la comptabilisation
    // (especes/mobile_money/virement/cheque). Hors espèces, l'utilisateur choisit un SUPPORT de
    // trésorerie de l'agence de la facture (`compte_tresorerie_id`) : un moyen sans support actif
    // n'existe pas, et l'opérateur Mobile Money est celui du support, jamais une saisie libre.

    /** @param  array<string, mixed>  $donnees */
    private function poster(User $user, FactureVente $facture, array $donnees)
    {
        return $this->actingAs($user)->post(route('encaissements.store', $facture), array_merge([
            'montant' => 1000,
            'date_encaissement' => now()->toDateString(),
        ], $donnees));
    }

    public function test_encaissement_mobile_money_sans_support_est_refuse(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        $this->poster($user, $facture, [
            'mode_paiement' => 'mobile_money',
            'reference_paiement' => 'OM-123456',
        ])->assertSessionHasErrors('compte_tresorerie_id');

        $this->assertDatabaseMissing('encaissements_ventes', ['facture_vente_id' => $facture->id]);
    }

    public function test_encaissement_mobile_money_sans_reference_est_refuse(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $orange = $this->creerSupportAgence($facture->site_id, 'mobile_money', '561100', 'orange_money');

        $this->poster($user, $facture, [
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $orange->id,
        ])->assertSessionHasErrors('reference_paiement');

        $this->assertDatabaseMissing('encaissements_ventes', ['facture_vente_id' => $facture->id]);
    }

    public function test_encaissement_virement_sans_reference_est_refuse(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $banque = $this->creerSupportAgence($facture->site_id, 'banque', '521000');

        $this->poster($user, $facture, [
            'mode_paiement' => 'virement',
            'compte_tresorerie_id' => $banque->id,
        ])->assertSessionHasErrors('reference_paiement');

        $this->assertDatabaseMissing('encaissements_ventes', ['facture_vente_id' => $facture->id]);
    }

    public function test_encaissement_cheque_sans_reference_est_accepte(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $banque = $this->creerSupportAgence($facture->site_id, 'banque', '521000');

        $this->poster($user, $facture, [
            'mode_paiement' => 'cheque',
            'compte_tresorerie_id' => $banque->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('encaissements_ventes', [
            'facture_vente_id' => $facture->id,
            'mode_paiement' => 'cheque',
            'compte_tresorerie_id' => $banque->id,
            'reference_paiement' => null,
        ]);
    }

    public function test_l_operateur_enregistre_est_celui_du_support_jamais_la_saisie(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $kulu = $this->creerSupportAgence($facture->site_id, 'mobile_money', '561400', 'kulu');

        // Requête forgée : l'opérateur envoyé contredit le support choisi — il est ignoré.
        $this->poster($user, $facture, [
            'mode_paiement' => 'mobile_money',
            'operateur_mobile_money' => 'orange_money',
            'compte_tresorerie_id' => $kulu->id,
            'reference_paiement' => 'KUL-987654',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('encaissements_ventes', [
            'facture_vente_id' => $facture->id,
            'mode_paiement' => 'mobile_money',
            'operateur_mobile_money' => 'kulu',
            'compte_tresorerie_id' => $kulu->id,
            'reference_paiement' => 'KUL-987654',
        ]);
    }

    public function test_un_support_d_une_autre_agence_est_refuse(): void
    {
        ['org' => $org, 'facture' => $facture, 'user' => $user] = $this->creerContexte();
        $autreAgence = Site::create(['organization_id' => $org->id, 'nom' => 'Kindia', 'type' => 'depot', 'localisation' => 'Kindia']);
        $kuluKindia = $this->creerSupportAgence($autreAgence->id, 'mobile_money', '561400', 'kulu');

        $this->poster($user, $facture, [
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $kuluKindia->id,
            'reference_paiement' => 'KUL-1',
        ])->assertSessionHasErrors('compte_tresorerie_id');

        $this->assertDatabaseMissing('encaissements_ventes', ['facture_vente_id' => $facture->id]);
    }

    public function test_un_support_d_une_autre_organisation_est_refuse(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $autre = $this->creerContexte();
        $orangeAutreOrg = $this->creerSupportAgence($autre['facture']->site_id, 'mobile_money', '561100', 'orange_money');

        $this->poster($user, $facture, [
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $orangeAutreOrg->id,
            'reference_paiement' => 'OM-1',
        ])->assertSessionHasErrors('compte_tresorerie_id');

        $this->assertDatabaseMissing('encaissements_ventes', ['facture_vente_id' => $facture->id]);
    }

    public function test_un_support_inactif_ou_en_brouillon_est_refuse(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $inactif = $this->creerSupportAgence($facture->site_id, 'mobile_money', '561100', 'orange_money');
        $inactif->update(['actif' => false]);
        $brouillon = CompteTresorerie::create([
            'organization_id' => $facture->organization_id,
            'site_id' => $facture->site_id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $facture->organization_id)->where('numero', '561400')->firstOrFail()->id,
            'type' => 'mobile_money',
            'operateur_mobile_money' => 'kulu',
            'actif' => false,
        ]);

        foreach ([$inactif, $brouillon] as $support) {
            $this->poster($user, $facture, [
                'mode_paiement' => 'mobile_money',
                'compte_tresorerie_id' => $support->id,
                'reference_paiement' => 'REF-1',
            ])->assertSessionHasErrors('compte_tresorerie_id');
        }

        $this->assertDatabaseMissing('encaissements_ventes', ['facture_vente_id' => $facture->id]);
    }

    public function test_un_mode_incoherent_avec_le_support_est_refuse(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $orange = $this->creerSupportAgence($facture->site_id, 'mobile_money', '561100', 'orange_money');

        // Un wallet Mobile Money ne reçoit jamais un virement.
        $this->poster($user, $facture, [
            'mode_paiement' => 'virement',
            'compte_tresorerie_id' => $orange->id,
            'reference_paiement' => 'VIR-1',
        ])->assertSessionHasErrors('compte_tresorerie_id');

        $this->assertDatabaseMissing('encaissements_ventes', ['facture_vente_id' => $facture->id]);
    }

    public function test_un_mobile_money_sans_operateur_renseigne_n_est_jamais_accepte(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $sansOperateur = $this->creerSupportAgence($facture->site_id, 'mobile_money', '561000');

        $this->poster($user, $facture, [
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $sansOperateur->id,
            'reference_paiement' => 'REF-1',
        ])->assertSessionHasErrors('compte_tresorerie_id');
    }

    public function test_encaissement_sans_date_utilise_date_du_jour(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 2000,
                'mode_paiement' => 'especes',
                // date_encaissement absent : doit defaulter à today()
            ]
        )->assertRedirect();

        $enc = $facture->encaissements()->first();
        $this->assertNotNull($enc);
        $this->assertEquals(now()->toDateString(), $enc->date_encaissement->toDateString());
    }

    public function test_store_returns_403_for_facture_from_other_organization(): void
    {
        ['facture' => $facture] = $this->creerContexte();
        $autreUser = $this->utilisateur(Organization::factory()->create());

        $this->actingAs($autreUser)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        )->assertStatus(403);

        $this->assertSame(0, $facture->fresh()->encaissements()->count());
    }

    // ── Encaissement destroy ──────────────────────────────────────────────────

    public function test_destroy_returns_403_for_encaissement_from_other_organization(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $enc = $facture->encaissements()->create([
            'montant' => 1000, 'date_encaissement' => now()->toDateString(), 'mode_paiement' => 'especes',
        ]);
        // Avec la permission : c'est bien l'isolation entre organisations qui refuse ici.
        $autreUser = $this->avecPermissionSuppression($this->utilisateur(Organization::factory()->create()));

        $this->actingAs($autreUser)
            ->delete(route('encaissements.destroy', $enc))
            ->assertStatus(403);

        $this->assertNotNull($enc->fresh());
    }

    /**
     * Non-régression (24/09/2026) : la route n'exigeait aucune permission — tout utilisateur du
     * module Ventes pouvait supprimer un encaissement de son organisation par requête directe.
     */
    public function test_destroy_returns_403_sans_permission_annulation_exceptionnelle(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $enc = $facture->encaissements()->create([
            'montant' => 1000, 'date_encaissement' => now()->toDateString(), 'mode_paiement' => 'especes',
        ]);

        $this->actingAs($user)
            ->delete(route('encaissements.destroy', $enc))
            ->assertStatus(403);

        $this->assertNotNull($enc->fresh());
    }

    private function avecPermissionSuppression(User $user): User
    {
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ventes.annuler_exceptionnel', 'guard_name' => 'web']));

        return $user;
    }

    public function test_suppression_encaissement_recalcule_statut_facture(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $this->avecPermissionSuppression($user);

        // Ajouter deux encaissements partiels
        $enc1 = $facture->encaissements()->create([
            'montant' => 2000, 'date_encaissement' => now()->toDateString(), 'mode_paiement' => 'especes',
        ]);
        $facture->encaissements()->create([
            'montant' => 1000, 'date_encaissement' => now()->toDateString(), 'mode_paiement' => 'especes',
        ]);
        $facture->recalculStatut();

        $this->assertEquals(StatutFactureVente::PARTIEL, $facture->fresh()->statut_facture);

        // Supprimer le premier
        $this->actingAs($user)->delete(route('encaissements.destroy', $enc1));

        $this->assertEquals(StatutFactureVente::PARTIEL, $facture->fresh()->statut_facture);
        $this->assertEquals(1000.0, (float) $facture->fresh()->montant_encaisse);
    }

    // ── Mobile Money : l'argent arrive sur le compte du support choisi (bug préprod 24/09/2026) ──
    // Avant correctif, un encaissement Kulu (sans wallet dédié) partait sur 561000, qu'aucun support
    // n'affichait : le solde du support Mobile Money de l'agence restait figé.

    private function encaisserSur(User $user, FactureVente $facture, float $montant, CompteTresorerie $support): void
    {
        $this->poster($user, $facture, [
            'montant' => $montant,
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $support->id,
            'reference_paiement' => 'REF-'.$support->id.'-'.$montant,
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_chaque_mobile_money_alimente_son_propre_support(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $facture->update(['montant_net' => 10_000_000]);
        $orange = $this->creerSupportAgence($facture->site_id, 'mobile_money', '561100', 'orange_money');
        $kulu = $this->creerSupportAgence($facture->site_id, 'mobile_money', '561400', 'kulu');
        $soldes = app(TresorerieDisponibiliteService::class);

        $this->encaisserSur($user, $facture, 1_000_000, $orange);
        $this->encaisserSur($user, $facture, 500_000, $kulu);

        $this->assertEqualsWithDelta(1_000_000.0, $soldes->soldePourSupport($orange), 0.01);
        $this->assertEqualsWithDelta(500_000.0, $soldes->soldePourSupport($kulu), 0.01);

        $dernier = EncaissementVente::where('facture_vente_id', $facture->id)->where('operateur_mobile_money', 'kulu')->firstOrFail();
        $lignes = PieceComptable::where('source_id', $dernier->id)->firstOrFail()->lignes()->with('compte')->get();
        $this->assertEqualsWithDelta(500_000.0, (float) $lignes->firstWhere('compte.numero', '561400')->debit, 0.01);
        $this->assertEqualsWithDelta(500_000.0, (float) $lignes->firstWhere('compte.numero', '411000')->credit, 0.01);
        $this->assertNull($lignes->firstWhere('compte.numero', '561000'), 'Plus jamais de repli sur le Mobile Money générique');
    }

    public function test_le_virement_alimente_la_banque_choisie(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $banque = $this->creerSupportAgence($facture->site_id, 'banque', '521000', null, 'UBA');

        $this->poster($user, $facture, [
            'montant' => 3000,
            'mode_paiement' => 'virement',
            'compte_tresorerie_id' => $banque->id,
            'reference_paiement' => 'VIR-1',
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(3000.0, app(TresorerieDisponibiliteService::class)->soldePourSupport($banque), 0.01);
    }

    // ── Moyens proposés à l'écran : uniquement les supports actifs de l'agence ──────────

    public function test_la_fiche_vente_ne_propose_que_les_moyens_des_supports_actifs_de_l_agence(): void
    {
        ['org' => $org, 'commande' => $commande, 'facture' => $facture, 'user' => $user] = $this->creerContexte();
        $commande->update(['site_id' => $facture->site_id]);
        Permission::firstOrCreate(['name' => 'ventes.read', 'guard_name' => 'web']);
        $user->givePermissionTo('ventes.read');

        $orange = $this->creerSupportAgence($facture->site_id, 'mobile_money', '561100', 'orange_money');
        $banque = $this->creerSupportAgence($facture->site_id, 'banque', '521000', null, 'UBA');
        // Ni un support d'une autre agence, ni un support inactif, ni un Mobile Money sans opérateur.
        $autreAgence = Site::create(['organization_id' => $org->id, 'nom' => 'Kindia', 'type' => 'depot', 'localisation' => 'Kindia']);
        $this->creerSupportAgence($autreAgence->id, 'mobile_money', '561400', 'kulu');
        $this->creerSupportAgence($facture->site_id, 'mobile_money', '561200', 'momo')->update(['actif' => false]);
        $this->creerSupportAgence($facture->site_id, 'mobile_money', '561000');

        $this->actingAs($user)->get(route('ventes.show', $commande))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('commande.moyens_encaissement', [
                    [
                        'key' => "mobile_money:{$orange->id}",
                        'label' => 'Orange Money',
                        'mode_paiement' => 'mobile_money',
                        'operateur_mobile_money' => 'orange_money',
                        'compte_tresorerie_id' => $orange->id,
                        'reference_requise' => true,
                    ],
                    [
                        'key' => "virement:{$banque->id}",
                        'label' => 'Virement bancaire — UBA',
                        'mode_paiement' => 'virement',
                        'operateur_mobile_money' => null,
                        'compte_tresorerie_id' => $banque->id,
                        'reference_requise' => true,
                    ],
                    [
                        'key' => "cheque:{$banque->id}",
                        'label' => 'Chèque — UBA',
                        'mode_paiement' => 'cheque',
                        'operateur_mobile_money' => null,
                        'compte_tresorerie_id' => $banque->id,
                        'reference_requise' => false,
                    ],
                ]));
    }
}
