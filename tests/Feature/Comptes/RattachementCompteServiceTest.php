<?php

namespace Tests\Feature\Comptes;

use App\Enums\AuditEvent;
use App\Enums\PrestataireType;
use App\Models\AuditLog;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Organization;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\User;
use App\Services\Comptes\RattachementCompteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Rattachement manuel d'un compte à une fiche parrain, consultant ou interlocuteur (ADR 0050).
 */
class RattachementCompteServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $acteur;

    private RattachementCompteService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->acteur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->service = app(RattachementCompteService::class);
    }

    private function compte(array $attributs = [], array $roles = ['client']): User
    {
        $compte = User::factory()->create(['organization_id' => null, ...$attributs]);

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $compte->assignRole($role);
        }

        return $compte;
    }

    private function parrain(array $attributs = []): Parrain
    {
        return Parrain::factory()->create(['organization_id' => $this->org->id, ...$attributs]);
    }

    private function consultantPhysique(array $attributs = []): Prestataire
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);

        return Prestataire::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'type' => PrestataireType::CONSULTANT->value,
            ...$attributs,
        ]);
    }

    private function entreprise(array $attributs = []): EntrepriseTierce
    {
        return EntrepriseTierce::create([
            'organization_id' => $this->org->id,
            'raison_sociale' => 'ABC CONSULTING',
            ...$attributs,
        ]);
    }

    private function interlocuteur(EntrepriseTierce $entreprise, array $attributs = []): EntrepriseInterlocuteur
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);

        return EntrepriseInterlocuteur::create([
            'organization_id' => $this->org->id,
            'entreprise_tierce_id' => $entreprise->id,
            'personne_id' => $personne->id,
            'acces_commissions' => true,
            ...$attributs,
        ]);
    }

    private function assertRefuse(Parrain|Prestataire|EntrepriseInterlocuteur $fiche, User $compte, string $motif): void
    {
        $anciennement = $fiche->user_id;

        try {
            $this->service->rattacher($fiche, $compte, $this->acteur);
            $this->fail('Le rattachement aurait dû être refusé.');
        } catch (ValidationException $e) {
            $this->assertSame($motif, $e->errors()['compte'][0]);
        }

        $this->assertSame($anciennement, $fiche->fresh()->user_id);
    }

    // ── Rattacher ─────────────────────────────────────────────────────────────

    public function test_rattacher_un_parrain_renseigne_le_compte_et_son_organisation_et_trace(): void
    {
        $parrain = $this->parrain();
        $compte = $this->compte();

        $this->service->rattacher($parrain, $compte, $this->acteur);

        $this->assertSame($compte->id, $parrain->fresh()->user_id);
        $this->assertSame($this->org->id, $compte->fresh()->organization_id);

        $trace = AuditLog::where('event_code', AuditEvent::COMPTE_RATTACHE->value)->sole();
        $this->assertSame($parrain->id, $trace->auditable_id);
        $this->assertSame($this->acteur->id, $trace->actor_id);
        $this->assertSame($compte->id, $trace->new_values['user_id']);
    }

    public function test_rattacher_deux_fois_le_meme_compte_ne_trace_qu_une_fois(): void
    {
        $parrain = $this->parrain();
        $compte = $this->compte();

        $this->service->rattacher($parrain, $compte, $this->acteur);
        $this->service->rattacher($parrain, $compte, $this->acteur);

        $this->assertSame(1, AuditLog::where('event_code', AuditEvent::COMPTE_RATTACHE->value)->count());
    }

    public function test_rattacher_ne_fusionne_ni_ne_supprime_aucune_fiche_personne(): void
    {
        $parrain = $this->parrain();
        $compte = $this->compte();
        $personneDuCompte = $compte->personne_id;
        $personneDuParrain = $parrain->personne_id;
        $nombreDePersonnes = Personne::count();

        $this->service->rattacher($parrain, $compte, $this->acteur);

        $this->assertSame($nombreDePersonnes, Personne::count());
        $this->assertSame($personneDuCompte, $compte->fresh()->personne_id);
        $this->assertSame($personneDuParrain, $parrain->fresh()->personne_id);
    }

    public function test_rattacher_un_consultant_personne_physique(): void
    {
        $consultant = $this->consultantPhysique();
        $compte = $this->compte();

        $this->service->rattacher($consultant, $compte, $this->acteur);

        $this->assertSame($compte->id, $consultant->fresh()->user_id);
    }

    public function test_un_compte_peut_cumuler_parrain_et_interlocuteurs_de_plusieurs_entreprises(): void
    {
        $compte = $this->compte();
        $parrain = $this->parrain();
        $premier = $this->interlocuteur($this->entreprise());
        $second = $this->interlocuteur($this->entreprise(['raison_sociale' => 'XYZ CONSEIL']));

        $this->service->rattacher($parrain, $compte, $this->acteur);
        $this->service->rattacher($premier, $compte, $this->acteur);
        $this->service->rattacher($second, $compte, $this->acteur);

        $this->assertSame($compte->id, $parrain->fresh()->user_id);
        $this->assertSame($compte->id, $premier->fresh()->user_id);
        $this->assertSame($compte->id, $second->fresh()->user_id);
    }

    // ── Refus ─────────────────────────────────────────────────────────────────

    public function test_refuse_une_fiche_deja_rattachee_a_un_autre_compte(): void
    {
        $parrain = $this->parrain();
        $this->service->rattacher($parrain, $this->compte(), $this->acteur);

        $this->assertRefuse($parrain->fresh(), $this->compte(), 'Cette fiche est déjà rattachée à un autre compte.');
    }

    public function test_refuse_un_compte_desactive(): void
    {
        $this->assertRefuse(
            $this->parrain(),
            $this->compte(['is_active' => false]),
            "Ce compte est désactivé ou n'est pas encore activé.",
        );
    }

    public function test_refuse_un_compte_d_une_autre_organisation(): void
    {
        $autreOrganisation = Organization::factory()->create();

        $this->assertRefuse(
            $this->parrain(),
            $this->compte(['organization_id' => $autreOrganisation->id]),
            'Ce compte appartient à une autre organisation.',
        );
    }

    // ADR 0055 : remplace l'ancien refus du compte staff (ADR 0050, règle 7) — un compte unique.
    public function test_rattache_le_compte_d_un_agent_du_back_office_sans_toucher_a_ses_roles(): void
    {
        $parrain = $this->parrain();
        $agent = $this->compte(['organization_id' => $this->org->id], ['comptable']);

        $this->service->rattacher($parrain, $agent, $this->acteur);

        $this->assertSame($agent->id, $parrain->fresh()->user_id);
        $this->assertSame(['comptable'], $agent->fresh()->getRoleNames()->all());
        $this->assertNull($this->service->motifRefus($this->consultantPhysique(['is_active' => true]), $agent->fresh()));
    }

    public function test_refuse_une_fiche_desactivee(): void
    {
        $parrain = $this->parrain();
        $parrain->update(['is_active' => false]);

        $this->assertRefuse($parrain, $this->compte(), 'Cette fiche est désactivée.');
    }

    public function test_refuse_un_compte_deja_rattache_a_un_autre_parrain(): void
    {
        $compte = $this->compte();
        $this->service->rattacher($this->parrain(), $compte, $this->acteur);

        $this->assertRefuse($this->parrain(), $compte->fresh(), 'Ce compte est déjà rattaché à un autre parrain.');
    }

    public function test_refuse_un_compte_deja_rattache_a_un_autre_consultant(): void
    {
        $compte = $this->compte();
        $this->service->rattacher($this->consultantPhysique(), $compte, $this->acteur);

        $this->assertRefuse($this->consultantPhysique(), $compte->fresh(), 'Ce compte est déjà rattaché à un autre consultant.');
    }

    public function test_refuse_de_rattacher_directement_une_entreprise(): void
    {
        $entreprise = $this->entreprise();
        $prestataire = Prestataire::create([
            'organization_id' => $this->org->id,
            'entreprise_tierce_id' => $entreprise->id,
            'type' => PrestataireType::CONSULTANT->value,
        ]);

        $this->assertRefuse($prestataire, $this->compte(), 'Une entreprise se rattache par ses interlocuteurs.');
    }

    public function test_refuse_un_prestataire_qui_n_est_pas_consultant(): void
    {
        $this->assertRefuse(
            $this->consultantPhysique(['type' => PrestataireType::MECANICIEN->value]),
            $this->compte(),
            'Seul un prestataire consultant peut être rattaché à un compte.',
        );
    }

    public function test_refuse_un_second_interlocuteur_du_meme_compte_sur_la_meme_entreprise(): void
    {
        $entreprise = $this->entreprise();
        $compte = $this->compte();
        $this->service->rattacher($this->interlocuteur($entreprise), $compte, $this->acteur);

        $this->assertRefuse($this->interlocuteur($entreprise), $compte->fresh(), 'Ce compte est déjà interlocuteur de cette entreprise.');
    }

    public function test_refuse_un_interlocuteur_d_une_entreprise_desactivee(): void
    {
        $this->assertRefuse(
            $this->interlocuteur($this->entreprise(['is_active' => false])),
            $this->compte(),
            "L'entreprise de cet interlocuteur est désactivée.",
        );
    }

    public function test_refuse_un_responsable_d_une_autre_organisation(): void
    {
        $parrain = $this->parrain();
        $autreOrganisation = Organization::factory()->create();
        $autreActeur = User::factory()->create(['organization_id' => $autreOrganisation->id]);

        $this->expectException(HttpException::class);

        $this->service->rattacher($parrain, $this->compte(), $autreActeur);
    }

    // ── Détacher ──────────────────────────────────────────────────────────────

    public function test_detacher_coupe_le_lien_sans_toucher_au_compte_et_trace(): void
    {
        $parrain = $this->parrain();
        $compte = $this->compte();
        $this->service->rattacher($parrain, $compte, $this->acteur);

        $this->service->detacher($parrain, $this->acteur);

        $this->assertNull($parrain->fresh()->user_id);
        $this->assertTrue($compte->fresh()->is_active);
        $this->assertSame($this->org->id, $compte->fresh()->organization_id);

        $trace = AuditLog::where('event_code', AuditEvent::COMPTE_DETACHE->value)->sole();
        $this->assertSame($compte->id, $trace->old_values['user_id']);
    }

    public function test_detacher_une_fiche_sans_compte_ne_trace_rien(): void
    {
        $parrain = $this->parrain();

        $this->service->detacher($parrain, $this->acteur);

        $this->assertSame(0, AuditLog::where('event_code', AuditEvent::COMPTE_DETACHE->value)->count());
    }

    // ── Vérification par le responsable ───────────────────────────────────────

    /** Sur un consultant : un parrain au même numéro est relié automatiquement (ADR 0056). */
    public function test_le_compte_propose_par_telephone_est_seulement_montre_jamais_relie(): void
    {
        $telephone = '+224620000321';
        $personne = Personne::factory()->create(['organization_id' => $this->org->id, 'telephone' => $telephone]);
        $consultant = $this->consultantPhysique(['personne_id' => $personne->id]);
        $compte = $this->compte(['telephone' => $telephone]);

        $propose = $this->service->compteParTelephone($telephone);

        $this->assertSame($compte->id, $propose?->id);
        $this->assertNull($consultant->fresh()->user_id);

        $apercu = $this->service->apercu($consultant->fresh(), $propose);
        $this->assertSame($telephone, $apercu['telephone']);
        $this->assertSame(['client'], $apercu['roles']);
        $this->assertNotNull($apercu['inscrit_le']);
        $this->assertNull($apercu['motif_refus']);
    }

    public function test_l_apercu_indique_le_motif_de_refus(): void
    {
        $apercu = $this->service->apercu($this->parrain(), $this->compte(['is_active' => false]));

        $this->assertSame("Ce compte est désactivé ou n'est pas encore activé.", $apercu['motif_refus']);
    }

    public function test_le_compte_ne_se_renseigne_pas_par_affectation_de_masse(): void
    {
        $compte = $this->compte();

        $parrain = $this->parrain();
        $parrain->update(['user_id' => $compte->id]);

        $this->assertNull($parrain->fresh()->user_id);
    }

    // ── L'inscription et la connexion ne changent pas ─────────────────────────

    public function test_l_inscription_et_la_connexion_ne_relient_jamais_un_parrain_ni_un_consultant(): void
    {
        Mail::fake();

        $telephone = '+224620000654';
        $personne = Personne::factory()->create(['organization_id' => $this->org->id, 'telephone' => $telephone]);
        $parrain = Parrain::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $personne->id]);
        $consultant = $this->consultantPhysique(['personne_id' => $personne->id]);

        $this->postJson(route('api.auth.register.store'), [
            'telephone' => $telephone,
            'prenom' => 'Mamadou',
            'nom' => 'Diallo',
            'password' => 'Passw0rd!2024',
            'password_confirmation' => 'Passw0rd!2024',
        ])->assertCreated();

        $this->postJson(route('api.auth.login'), [
            'telephone' => $telephone,
            'password' => 'Passw0rd!2024',
            'device_name' => 'test',
        ])->assertOk();

        $this->assertNull($parrain->fresh()->user_id);
        $this->assertNull($consultant->fresh()->user_id);
    }
}
