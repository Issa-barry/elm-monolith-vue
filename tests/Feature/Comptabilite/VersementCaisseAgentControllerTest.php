<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\NatureMouvementFonds;
use App\Enums\StatutMouvementFonds;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\MouvementFondsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Versement d'une caisse dédiée (phase 3) côté HTTP : permission `tresorerie.verser`, portée par
 * agence, agent limité à SA caisse, erreurs de validation, confirmation de réception par un
 * autre utilisateur, indicateurs `peut_*` de l'écran Mouvements (états explicites malgré le
 * bypass super admin) et accès en lecture à l'écran Supports limité à ses agences.
 */
class VersementCaisseAgentControllerTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    private CompteTresorerie $caisseAgence;

    private CompteTresorerie $caisseAgent;

    private User $agent;

    private User $responsable;

    private User $receveur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read', 'tresorerie.verser']);
        $this->site = $this->user->sites()->first();

        $this->caisseAgence = $this->creerCaisseAgence($this->site, 'Caisse principale');
        $this->agent = $this->creerAgent($this->site);
        $this->caisseAgent = $this->creerCaisseActive($this->site->id, $this->agent->id);
        $this->alimenterCaisse($this->caisseAgent, 1_150_000);

        $this->responsable = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.verser', 'tresorerie.envoyer'], 'Fatoumata', 'Responsable');
        $this->receveur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.recevoir', 'tresorerie.rejeter'], 'Ibrahima', 'Caissier');
    }

    private function creerCaisseAgence(Site $site, string $libelle, string $numeroCompte = '571000', string $type = 'caisse'): CompteTresorerie
    {
        return CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $this->org->id)->where('numero', $numeroCompte)->firstOrFail()->id,
            'type' => $type,
            'libelle' => $libelle,
        ]);
    }

    private function urlVerser(CompteTresorerie $caisse): string
    {
        return route('comptabilite.tresorerie.supports.verser', $caisse);
    }

    /** @return array<string, mixed> */
    private function payload(array $surcharge = []): array
    {
        return array_merge([
            'compte_tresorerie_destination_id' => $this->caisseAgence->id,
            'montant' => 800_000,
            'motif' => 'Versement caisse agent',
        ], $surcharge);
    }

    private function versementEnvoye(?User $auteur = null): MouvementFonds
    {
        return app(MouvementFondsService::class)->verserCaisseAgent(
            $this->org->id, $this->caisseAgent, $this->caisseAgence->id, 800_000, 'Versement caisse agent', ($auteur ?? $this->responsable)->id,
        );
    }

    /** @return array<string, mixed> */
    private function props(User $user, string $route, array $query = []): array
    {
        return $this->actingAs($user)
            ->get(route($route).($query ? '?'.http_build_query($query) : ''))
            ->assertOk()
            ->viewData('page')['props'];
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => false]);

        return $superAdmin;
    }

    // ── Versement : permissions et portée ────────────────────────────────────

    public function test_un_responsable_verse_la_caisse_d_un_agent(): void
    {
        $this->actingAs($this->responsable)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post($this->urlVerser($this->caisseAgent), $this->payload())
            ->assertRedirect(route('comptabilite.tresorerie.supports.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $mouvement = MouvementFonds::firstOrFail();
        $this->assertSame(NatureMouvementFonds::INTERNE_CAISSES, $mouvement->nature);
        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->statut);
        $this->assertSame($this->responsable->id, $mouvement->sent_by);
        $this->assertSame($this->caisseAgent->id, $mouvement->compte_tresorerie_origine_id);
        $this->assertEquals(800_000, $mouvement->montant);
    }

    public function test_un_agent_verse_sa_propre_caisse_mais_pas_celle_d_un_collegue(): void
    {
        $agentConnecte = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.verser'], 'Saa', 'Fodé');
        $saCaisse = $this->creerCaisseActive($this->site->id, $agentConnecte->id);
        $this->alimenterCaisse($saCaisse, 500_000);

        $this->actingAs($agentConnecte)
            ->post($this->urlVerser($saCaisse), $this->payload(['montant' => 300_000]))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, MouvementFonds::where('compte_tresorerie_origine_id', $saCaisse->id)->count());

        $this->actingAs($agentConnecte)
            ->post($this->urlVerser($this->caisseAgent), $this->payload())
            ->assertForbidden();
        $this->assertSame(0, MouvementFonds::where('compte_tresorerie_origine_id', $this->caisseAgent->id)->count());
    }

    public function test_sans_la_permission_verser_l_acces_est_refuse(): void
    {
        $sansPermission = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.envoyer', 'tresorerie.recevoir']);

        $this->actingAs($sansPermission)->post($this->urlVerser($this->caisseAgent), $this->payload())->assertForbidden();
        $this->assertSame(0, MouvementFonds::count());
    }

    public function test_un_utilisateur_d_une_autre_agence_ne_peut_pas_verser(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $etranger = $this->creerUtilisateurNonAdmin($autreSite, ['tresorerie.read', 'tresorerie.verser', 'tresorerie.envoyer']);

        $this->actingAs($etranger)->post($this->urlVerser($this->caisseAgent), $this->payload())->assertForbidden();
    }

    public function test_un_utilisateur_d_une_autre_organisation_ne_peut_pas_verser(): void
    {
        $autreOrg = Organization::factory()->create();
        $intrus = $this->makeUserWithPermissions($autreOrg, ['tresorerie.verser', 'tresorerie.envoyer']);
        $siteEtranger = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Étranger', 'type' => 'agence', 'localisation' => 'Labé']);
        $intrus->sites()->attach($siteEtranger->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($intrus)->post($this->urlVerser($this->caisseAgent), $this->payload())->assertForbidden();
        $this->assertSame(0, MouvementFonds::count());
    }

    public function test_une_caisse_d_agence_ne_se_verse_pas(): void
    {
        $this->actingAs($this->responsable)
            ->post($this->urlVerser($this->caisseAgence), $this->payload())
            ->assertForbidden();
    }

    // ── Versement : validation ───────────────────────────────────────────────

    public function test_un_solde_insuffisant_renvoie_une_erreur_sur_le_montant(): void
    {
        $this->actingAs($this->responsable)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post($this->urlVerser($this->caisseAgent), $this->payload(['montant' => 2_000_000]))
            ->assertSessionHasErrors('montant');

        $this->assertSame(0, MouvementFonds::count());
    }

    public function test_un_montant_formate_avec_des_espaces_est_accepte(): void
    {
        $this->actingAs($this->responsable)
            ->post($this->urlVerser($this->caisseAgent), $this->payload(['montant' => "800\u{202F}000"]))
            ->assertSessionHasNoErrors();

        $this->assertEquals(800_000, MouvementFonds::firstOrFail()->montant);
    }

    public function test_les_champs_invalides_sont_signales(): void
    {
        $this->actingAs($this->responsable)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post($this->urlVerser($this->caisseAgent), ['montant' => 'abc'])
            ->assertSessionHasErrors(['montant', 'compte_tresorerie_destination_id']);

        $this->actingAs($this->responsable)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post($this->urlVerser($this->caisseAgent), $this->payload(['montant' => 0]))
            ->assertSessionHasErrors('montant');

        $banque = $this->creerCaisseAgence($this->site, 'UBA', '521000', 'banque');
        $this->actingAs($this->responsable)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post($this->urlVerser($this->caisseAgent), $this->payload(['compte_tresorerie_destination_id' => $banque->id]))
            ->assertSessionHasErrors('compte_tresorerie_destination_id');

        $this->assertSame(0, MouvementFonds::count());
    }

    // ── Confirmation de réception ────────────────────────────────────────────

    public function test_un_autre_utilisateur_confirme_la_reception_sans_choisir_de_support(): void
    {
        $mouvement = $this->versementEnvoye();

        $this->actingAs($this->receveur)
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))
            ->assertSessionHasNoErrors();

        $mouvement->refresh();
        $this->assertSame(StatutMouvementFonds::RECU, $mouvement->statut);
        $this->assertSame($this->receveur->id, $mouvement->received_by);
        $this->assertSame($this->responsable->id, $mouvement->sent_by);
    }

    /**
     * Pour un utilisateur ordinaire, la policy refuse l'envoyeur EN AMONT (403) : le service, qui
     * porte la même règle (cf. VersementCaisseAgentServiceTest), reste le filet pour tout appel
     * qui ne passe pas par la policy.
     */
    public function test_l_envoyeur_ne_peut_pas_confirmer_la_reception_via_l_ecran(): void
    {
        // Le responsable reçoit aussi le droit de recevoir : seule la séparation l'en empêche.
        $this->responsable->givePermissionTo(Permission::firstOrCreate(['name' => 'tresorerie.recevoir', 'guard_name' => 'web']));
        $mouvement = $this->versementEnvoye();

        $this->actingAs($this->responsable)
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))
            ->assertForbidden();

        $mouvement->refresh();
        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->statut);
        $this->assertNull($mouvement->received_by);
    }

    public function test_l_envoyeur_ne_peut_pas_contester_via_l_ecran(): void
    {
        $this->responsable->givePermissionTo(Permission::firstOrCreate(['name' => 'tresorerie.rejeter', 'guard_name' => 'web']));
        $mouvement = $this->versementEnvoye();

        $this->actingAs($this->responsable)
            ->post(route('comptabilite.tresorerie.mouvements.contester', $mouvement), ['motif' => 'Erreur'])
            ->assertForbidden();

        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->fresh()->statut);
    }

    public function test_le_super_admin_envoyeur_peut_confirmer_sa_propre_reception_via_l_ecran(): void
    {
        $superAdmin = $this->superAdmin();
        $mouvement = $this->versementEnvoye($superAdmin);

        $this->actingAs($superAdmin)
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))
            ->assertSessionHasNoErrors();

        $mouvement->refresh();
        $this->assertSame(StatutMouvementFonds::RECU, $mouvement->statut);
        $this->assertSame($superAdmin->id, $mouvement->sent_by);
        $this->assertSame($superAdmin->id, $mouvement->received_by);
    }

    // ── Écran Mouvements ─────────────────────────────────────────────────────

    public function test_l_ecran_mouvements_expose_le_versement(): void
    {
        $mouvement = $this->versementEnvoye();

        $props = $this->props($this->receveur, 'comptabilite.tresorerie.mouvements.index');
        $ligne = collect($props['mouvements']['data'])->firstWhere('id', $mouvement->id);

        $this->assertSame('interne_caisses', $ligne['nature']);
        $this->assertSame('Versement de caisse', $ligne['nature_label']);
        $this->assertSame($this->caisseAgent->libelle, $ligne['compte_origine']);
        $this->assertSame('Caisse principale', $ligne['compte_destination']);
        $this->assertSame($this->caisseAgence->id, $ligne['compte_destination_id']);
        $this->assertSame('Versement caisse agent', $ligne['commentaire']);
        $this->assertSame($this->responsable->name, $ligne['expediteur']);
        $this->assertNull($ligne['receptionnaire']);
        $this->assertSame('envoye', $ligne['statut']);
    }

    public function test_les_indicateurs_de_l_ecran_respectent_la_separation_envoi_reception(): void
    {
        // Le responsable peut tout faire côté trésorerie, y compris recevoir et contester.
        foreach (['tresorerie.recevoir', 'tresorerie.rejeter'] as $permission) {
            $this->responsable->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $mouvement = $this->versementEnvoye($this->responsable);

        $ligne = fn (User $u) => collect($this->props($u, 'comptabilite.tresorerie.mouvements.index')['mouvements']['data'])->firstWhere('id', $mouvement->id);

        $chezReceveur = $ligne($this->receveur);
        $this->assertTrue($chezReceveur['peut_recevoir']);
        $this->assertTrue($chezReceveur['peut_contester']);

        $chezEnvoyeur = $ligne($this->responsable);
        $this->assertFalse($chezEnvoyeur['peut_recevoir'], 'l\'envoyeur ne voit pas « Confirmer réception »');
        $this->assertFalse($chezEnvoyeur['peut_contester']);

        $chezSuperAdmin = $ligne($this->superAdmin());
        $this->assertTrue($chezSuperAdmin['peut_recevoir']);
    }

    public function test_le_super_admin_ne_voit_aucune_action_sur_un_mouvement_termine(): void
    {
        $mouvement = $this->versementEnvoye();
        app(MouvementFondsService::class)->recevoir($mouvement, $this->receveur->id, $this->caisseAgence->id);

        $ligne = collect($this->props($this->superAdmin(), 'comptabilite.tresorerie.mouvements.index')['mouvements']['data'])->firstWhere('id', $mouvement->id);

        foreach (['peut_envoyer', 'peut_recevoir', 'peut_annuler', 'peut_contester', 'peut_confirmer_retour'] as $indicateur) {
            $this->assertFalse($ligne[$indicateur], "{$indicateur} ne doit pas s'afficher sur une ligne « Reçu », même pour un super admin");
        }
    }

    public function test_le_filtre_nature_de_l_ecran_mouvements(): void
    {
        $versement = $this->versementEnvoye();
        $siege = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège', 'type' => 'siege', 'localisation' => 'Conakry']);
        $caisseSiege = $this->creerCaisseAgence($siege, 'Caisse Siège');
        $entreAgences = app(MouvementFondsService::class)->creerBrouillon($this->org->id, [
            'site_origine_id' => $siege->id,
            'site_destination_id' => $this->site->id,
            'compte_tresorerie_origine_id' => $caisseSiege->id,
            'montant' => 10_000,
        ], $this->user->id);

        $ids = fn (array $query) => collect($this->props($this->user, 'comptabilite.tresorerie.mouvements.index', $query)['mouvements']['data'])->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$versement->id, $entreAgences->id])->sort()->values()->all(), $ids([]));
        $this->assertSame([$versement->id], $ids(['nature' => 'interne_caisses']));
        $this->assertSame([$entreAgences->id], $ids(['nature' => 'inter_sites']));
        $this->assertSame(collect([$versement->id, $entreAgences->id])->sort()->values()->all(), $ids(['nature' => 'inconnue']));
    }

    // ── Écran Supports : lecture, portée, indicateur peut_verser ─────────────

    public function test_un_lecteur_voit_seulement_les_supports_de_ses_agences_sans_donnees_de_gestion(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $this->creerCaisseAgence($autreSite, 'Caisse Kouria');
        $agentKouria = $this->creerAgent($autreSite, 'Bakary', 'Camara');
        $this->creerCaisseActive($autreSite->id, $agentKouria->id);

        $lecteur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read']);
        $props = $this->props($lecteur, 'comptabilite.tresorerie.supports.index');

        $sites = collect($props['comptes'])->pluck('site')->unique()->all();
        $this->assertSame([$this->site->nom], $sites, 'aucun support de l\'autre agence');
        $this->assertCount(2, $props['comptes']);
        $this->assertSame([$this->site->id], collect($props['sites'])->pluck('id')->all());
        $this->assertCount(0, $props['agents'], 'la liste des utilisateurs est réservée à la gestion');
        $this->assertCount(0, $props['comptes_comptables']);
        $this->assertCount(0, $props['caisses_dediees_actives']);
        $this->assertSame(['Caisse principale'], collect($props['destinations_versement'])->pluck('libelle')->all());
    }

    public function test_un_administrateur_voit_toutes_les_agences(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $this->creerCaisseAgence($autreSite, 'Caisse Kouria');

        $props = $this->props($this->user, 'comptabilite.tresorerie.supports.index');

        $this->assertEqualsCanonicalizing(
            [$this->site->nom, $autreSite->nom],
            collect($props['comptes'])->pluck('site')->unique()->values()->all(),
        );
    }

    public function test_sans_aucune_permission_de_tresorerie_l_ecran_supports_est_refuse(): void
    {
        $sansPermission = $this->creerUtilisateurNonAdmin($this->site, ['ventes.read']);

        $this->actingAs($sansPermission)->get(route('comptabilite.tresorerie.supports.index'))->assertForbidden();
    }

    public function test_un_lecteur_ne_peut_ni_creer_ni_modifier_un_support(): void
    {
        $lecteur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.verser', 'tresorerie.envoyer']);

        $this->actingAs($lecteur)
            ->post(route('comptabilite.tresorerie.supports.store'), ['nature' => 'dediee', 'site_id' => $this->site->id, 'agent_id' => $this->agent->id])
            ->assertForbidden();
        $this->actingAs($lecteur)
            ->put(route('comptabilite.tresorerie.supports.update', $this->caisseAgent), ['libelle' => 'Piratage', 'actif' => true])
            ->assertForbidden();
        $this->actingAs($lecteur)
            ->post(route('comptabilite.tresorerie.soldes-ouverture.store'), ['compte_tresorerie_id' => $this->caisseAgence->id, 'date_situation' => '2026-09-01', 'montant' => 1])
            ->assertForbidden();
    }

    public function test_peut_verser_pour_un_responsable(): void
    {
        $caisseVide = $this->creerCaisseActive($this->site->id, $this->creerAgent($this->site, 'Abdoulaye', 'Diallo')->id);
        $caisseDesactivee = $this->creerCaisseActive($this->site->id, $this->creerAgent($this->site, 'Bakary', 'Camara')->id);
        app(CaisseAgentService::class)->mettreAJour($caisseDesactivee, ['libelle' => $caisseDesactivee->libelle, 'actif' => false]);

        $comptes = collect($this->props($this->responsable, 'comptabilite.tresorerie.supports.index')['comptes'])->keyBy('id');

        $this->assertTrue($comptes[$this->caisseAgent->id]['peut_verser']);
        $this->assertFalse($comptes[$caisseVide->id]['peut_verser'], 'solde nul');
        $this->assertFalse($comptes[$caisseDesactivee->id]['peut_verser'], 'caisse désactivée');
        $this->assertFalse($comptes[$this->caisseAgence->id]['peut_verser'], 'une caisse d\'agence ne se verse pas');
    }

    public function test_peut_verser_est_faux_sans_caisse_d_agence_de_destination(): void
    {
        $this->caisseAgence->update(['actif' => false]);

        $comptes = collect($this->props($this->responsable, 'comptabilite.tresorerie.supports.index')['comptes'])->keyBy('id');

        $this->assertFalse($comptes[$this->caisseAgent->id]['peut_verser']);
    }

    public function test_peut_verser_est_faux_sans_la_permission_ou_pour_la_caisse_d_un_collegue(): void
    {
        $lecteur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read']);
        $this->assertFalse(collect($this->props($lecteur, 'comptabilite.tresorerie.supports.index')['comptes'])->firstWhere('id', $this->caisseAgent->id)['peut_verser']);

        $agentConnecte = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.verser'], 'Saa', 'Fodé');
        $saCaisse = $this->creerCaisseActive($this->site->id, $agentConnecte->id);
        $this->alimenterCaisse($saCaisse, 100_000);

        $comptes = collect($this->props($agentConnecte, 'comptabilite.tresorerie.supports.index')['comptes'])->keyBy('id');
        $this->assertTrue($comptes[$saCaisse->id]['peut_verser'], 'sa propre caisse');
        $this->assertFalse($comptes[$this->caisseAgent->id]['peut_verser'], 'la caisse d\'un collègue');
    }
}
