<?php

namespace Tests\Feature\Contacts;

use App\Enums\PrestataireType;
use App\Features\ModuleFeature;
use App\Models\AuditLog;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Site;
use App\Models\User;
use App\Services\Comptes\ActivitesCompteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultation des commissions de l'entreprise par un interlocuteur, depuis l'onglet Interlocuteurs
 * (COMPTE-006, CONTACT-026) : permission `rattachements-comptes.gerer`, entreprise visible et
 * consultante, interlocuteur actif pour autoriser, journal, effet immédiat sur l'espace client.
 */
class AccesCommissionsInterlocuteurTest extends TestCase
{
    use RefreshDatabase;

    private const DROITS = ['contacts.read', 'prestataires.read', 'fournisseurs.read', 'rattachements-comptes.gerer'];

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::PRESTATAIRES);
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence A', 'type' => 'depot', 'localisation' => 'Conakry']);
    }

    private function utilisateur(array $permissions = self::DROITS, ?Organization $org = null): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $org ??= $this->org;
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $site = $org->is($this->org) ? $this->site : Site::create(['organization_id' => $org->id, 'nom' => 'Siège', 'type' => 'depot', 'localisation' => 'Conakry']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function entrepriseConsultante(string $raisonSociale = 'Fello Consulting'): EntrepriseTierce
    {
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => $raisonSociale]);
        Prestataire::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'type' => PrestataireType::CONSULTANT->value]);

        return $entreprise;
    }

    private function entrepriseFournisseur(): EntrepriseTierce
    {
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Société Kaloum']);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id]);

        return $entreprise;
    }

    private function interlocuteur(EntrepriseTierce $entreprise, array $attributs = [], ?User $compte = null): EntrepriseInterlocuteur
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);
        $lien = EntrepriseInterlocuteur::create([
            'organization_id' => $this->org->id,
            'entreprise_tierce_id' => $entreprise->id,
            'personne_id' => $personne->id,
            'is_active' => true,
            ...$attributs,
        ]);
        if ($compte) {
            $lien->forceFill(['user_id' => $compte->id])->save();
        }

        return $lien;
    }

    private function changer(User $user, EntrepriseTierce $entreprise, EntrepriseInterlocuteur $lien, bool $acces): TestResponse
    {
        return $this->actingAs($user)->post(route('contacts.entreprises.interlocuteurs.acces-commissions', [
            'entreprise' => $entreprise->id, 'interlocuteur' => $lien->id,
        ]), ['acces_commissions' => $acces]);
    }

    private function consultantsVisiblesPar(User $compte): array
    {
        return app(ActivitesCompteResolver::class)->resolve($compte)->consultants->pluck('id')->all();
    }

    public function test_autoriser_ouvre_aussitot_les_commissions_au_compte_relie_et_journalise(): void
    {
        $entreprise = $this->entrepriseConsultante();
        $compte = User::factory()->create(['organization_id' => $this->org->id]);
        $lien = $this->interlocuteur($entreprise, [], $compte);
        $this->assertSame([], $this->consultantsVisiblesPar($compte));

        $this->changer($this->utilisateur(), $entreprise, $lien, true)
            ->assertRedirect(route('contacts.entreprises.show', ['entreprise' => $entreprise, 'tab' => 'interlocuteurs']))
            ->assertSessionHas('success');

        $this->assertTrue($lien->fresh()->acces_commissions);
        $this->assertCount(1, $this->consultantsVisiblesPar($compte));
        $journal = AuditLog::where('auditable_type', $lien->getMorphClass())->where('auditable_id', $lien->id)->sole();
        $this->assertSame(['updated', ['acces_commissions' => false], ['acces_commissions' => true]], [$journal->event_code, $journal->old_values, $journal->new_values]);
    }

    public function test_retirer_coupe_aussitot_l_acces(): void
    {
        $entreprise = $this->entrepriseConsultante();
        $compte = User::factory()->create(['organization_id' => $this->org->id]);
        $lien = $this->interlocuteur($entreprise, ['acces_commissions' => true], $compte);
        $this->assertCount(1, $this->consultantsVisiblesPar($compte));

        $this->changer($this->utilisateur(), $entreprise, $lien, false)->assertSessionHasNoErrors();

        $this->assertFalse($lien->fresh()->acces_commissions);
        $this->assertSame([], $this->consultantsVisiblesPar($compte));
    }

    public function test_sans_la_permission_de_rattachement_la_requete_est_refusee(): void
    {
        $entreprise = $this->entrepriseConsultante();
        $lien = $this->interlocuteur($entreprise);
        $user = $this->utilisateur(['contacts.read', 'prestataires.read', 'contacts.gerer_interlocuteurs']);

        $this->changer($user, $entreprise, $lien, true)->assertForbidden();
        $this->assertFalse($lien->fresh()->acces_commissions);
    }

    public function test_une_entreprise_non_visible_ou_d_une_autre_organisation_repond_404(): void
    {
        $entreprise = $this->entrepriseConsultante();
        $lien = $this->interlocuteur($entreprise);

        $sansPrestataires = $this->utilisateur(['contacts.read', 'rattachements-comptes.gerer']);
        $this->changer($sansPrestataires, $entreprise, $lien, true)->assertNotFound();

        $autreOrganisation = $this->utilisateur(self::DROITS, Organization::factory()->create());
        $this->changer($autreOrganisation, $entreprise, $lien, true)->assertNotFound();

        $this->assertFalse($lien->fresh()->acces_commissions);
    }

    public function test_un_interlocuteur_d_une_autre_entreprise_repond_404(): void
    {
        $entreprise = $this->entrepriseConsultante();
        $lienAutre = $this->interlocuteur($this->entrepriseConsultante('Autre Conseil'));

        $this->changer($this->utilisateur(), $entreprise, $lienAutre, true)->assertNotFound();
        $this->assertFalse($lienAutre->fresh()->acces_commissions);
    }

    public function test_une_entreprise_qui_n_est_pas_consultante_ne_peut_pas_ouvrir_l_acces(): void
    {
        $entreprise = $this->entrepriseFournisseur();
        $lien = $this->interlocuteur($entreprise);

        $this->changer($this->utilisateur(), $entreprise, $lien, true)->assertSessionHasErrors('acces_commissions');

        $this->assertFalse($lien->fresh()->acces_commissions);
        $this->assertSame(0, AuditLog::where('auditable_id', $lien->id)->count());
    }

    public function test_un_interlocuteur_desactive_ne_peut_pas_etre_autorise_mais_peut_perdre_l_acces(): void
    {
        $entreprise = $this->entrepriseConsultante();
        $inactif = $this->interlocuteur($entreprise, ['is_active' => false]);
        $inactifAutorise = $this->interlocuteur($entreprise, ['is_active' => false, 'acces_commissions' => true]);
        $user = $this->utilisateur();

        $this->changer($user, $entreprise, $inactif, true)->assertSessionHasErrors('acces_commissions');
        $this->changer($user, $entreprise, $inactifAutorise, false)->assertSessionHasNoErrors();

        $this->assertFalse($inactif->fresh()->acces_commissions);
        $this->assertFalse($inactifAutorise->fresh()->acces_commissions);
    }

    public function test_une_valeur_inchangee_n_ecrit_rien_au_journal(): void
    {
        $entreprise = $this->entrepriseConsultante();
        $lien = $this->interlocuteur($entreprise, ['acces_commissions' => true]);

        $this->changer($this->utilisateur(), $entreprise, $lien, true)->assertSessionHasNoErrors();

        $this->assertSame(0, AuditLog::where('auditable_id', $lien->id)->count());
    }

    public function test_la_fiche_transmet_l_acces_le_compte_et_les_droits(): void
    {
        $entreprise = $this->entrepriseConsultante();
        $this->interlocuteur($entreprise, ['acces_commissions' => true]);

        $this->actingAs($this->utilisateur())
            ->get(route('contacts.entreprises.show', $entreprise))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('commissions_consultables', true)
                ->where('peut_gerer_acces_commissions', true)
                ->where('interlocuteurs.0.acces_commissions', true)
                ->where('interlocuteurs.0.compte_relie', false));

        $this->actingAs($this->utilisateur(['contacts.read', 'fournisseurs.read']))
            ->get(route('contacts.entreprises.show', $this->entrepriseFournisseur()))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('commissions_consultables', false)
                ->where('peut_gerer_acces_commissions', false));
    }
}
