<?php

namespace Tests\Feature\Comptes;

use App\DTOs\RegisterData;
use App\Enums\AuditEvent;
use App\Enums\PrestataireType;
use App\Enums\StatutCommission;
use App\Enums\StatutCorrespondanceCompte;
use App\Models\AuditLog;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Comptes\ActivitesCompteResolver;
use App\Services\Comptes\RattachementAutomatiqueService;
use App\Services\Comptes\RattachementCompteService;
use App\Services\RegistrationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Rattachement automatique d'une fiche au compte de la même personne, et régularisation de
 * l'existant (ADR 0056).
 */
class RattachementAutomatiqueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private RattachementAutomatiqueService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->service = app(RattachementAutomatiqueService::class);
    }

    private function personne(array $attributs = []): Personne
    {
        return Personne::factory()->create(['organization_id' => $this->org->id, ...$attributs]);
    }

    private function compte(?Personne $personne = null, array $attributs = []): User
    {
        $compte = User::factory()->create([
            'organization_id' => $this->org->id,
            'personne_id' => ($personne ?? $this->personne())->id,
            ...$attributs,
        ]);
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $compte->assignRole('client');

        return $compte;
    }

    // Création directe : les factories Parrain et Livreur remplacent la personne qu'on leur passe.
    private function parrain(Personne $personne): Parrain
    {
        return Parrain::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'is_active' => true]);
    }

    private function livreur(Personne $personne, array $attributs = []): Livreur
    {
        return Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => $personne->nom_complet ?: 'Livreur',
            'is_active' => true,
            ...$attributs,
        ]);
    }

    /**
     * Données d'avant l'ADR 0056 : le compte a reçu la personne sans passer par un événement, ses
     * fiches sont restées sans `user_id`.
     */
    private function donnerPersonneSansEvenement(User $compte, Personne $personne): void
    {
        DB::table('users')->where('id', $compte->id)->update(['personne_id' => $personne->id]);
    }

    private function partParrain(CommandeVente $vente, Parrain $parrain, float $montant): void
    {
        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $vente->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => $montant,
            'montant_verse' => 0,
            'earned_at' => now(),
            'statut' => StatutCommission::IMPAYE,
        ]);
        CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PARRAIN,
            'beneficiaire_id' => $parrain->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
        ]);
    }

    private function rattachementAutomatique(Model $fiche): ?AuditLog
    {
        return AuditLog::query()
            ->where('auditable_type', $fiche->getMorphClass())
            ->where('auditable_id', $fiche->getKey())
            ->where('event_code', AuditEvent::COMPTE_RATTACHE->value)
            ->first();
    }

    // ── Au fil de l'eau ──────────────────────────────────────────────────────

    public function test_un_parrain_cree_pour_la_personne_d_un_compte_est_relie_automatiquement(): void
    {
        $personne = $this->personne();
        $compte = $this->compte($personne);

        $parrain = $this->parrain($personne);

        $this->assertSame($compte->id, $parrain->fresh()->user_id);
        $trace = $this->rattachementAutomatique($parrain);
        $this->assertNotNull($trace);
        $this->assertNull($trace->actor_id);
        $this->assertTrue($trace->meta['automatique']);
        $this->assertSame('fiche', $trace->meta['source']);
        $this->assertSame('Même fiche Personne que le compte.', $trace->meta['preuve']);
        $this->assertSame($parrain->id, app(ActivitesCompteResolver::class)->resolve($compte)->parrain?->id);
    }

    public function test_cas_fode_saa_livreur_relie_puis_parrain_du_vehicule_d_un_tiers(): void
    {
        // Le compte a sa propre fiche Personne ; la fiche livreur (autre Personne) lui est reliée.
        $compte = $this->compte();
        $personneLivreur = $this->personne();
        $this->livreur($personneLivreur, ['user_id' => $compte->id]);

        $parrain = $this->parrain($personneLivreur);
        $tiers = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $tiers->id,
            'parrain_id' => $parrain->id,
            'nom_vehicule' => 'ADAMA',
            'immatriculation' => 'OU114',
        ]);

        $this->assertSame($compte->id, $parrain->fresh()->user_id);
        $this->assertSame(
            'Même fiche Personne que sa fiche livreur, déjà reliée au compte.',
            $this->rattachementAutomatique($parrain)->meta['preuve'],
        );

        Sanctum::actingAs($compte, ['*']);
        $vehicules = collect($this->getJson(route('client.vehicules.rattaches'))->assertOk()->json())->keyBy('nom');
        $this->assertSame(['parrain'], $vehicules['ADAMA']['liens']);
    }

    public function test_un_compte_qui_recoit_la_personne_d_activites_existantes_les_retrouve(): void
    {
        $personne = $this->personne();
        $parrain = $this->parrain($personne);
        $consultant = Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'type' => PrestataireType::CONSULTANT->value]);
        $this->assertNull($parrain->fresh()->user_id);

        // Comme RegistrationService::rattacherMemePersonne() à l'inscription.
        $compte = $this->compte();
        $compte->update(['personne_id' => $personne->id]);

        $this->assertSame($compte->id, $parrain->fresh()->user_id);
        $this->assertSame($compte->id, $consultant->fresh()->user_id);
        $this->assertSame('compte', $this->rattachementAutomatique($parrain)->meta['source']);
    }

    public function test_l_inscription_d_un_livreur_qui_est_aussi_parrain_relie_les_deux_fiches(): void
    {
        $personne = Personne::resoudreOuCreer($this->org->id, ['nom_complet' => 'Hassan Diallo', 'telephone' => '+224666177007']);
        $livreur = $this->livreur($personne);
        $parrain = $this->parrain($personne);

        $compte = app(RegistrationService::class)->register(new RegisterData(
            telephone: '+224666177007',
            prenom: 'Hassan',
            nom: 'Diallo',
            password: 'Password@123',
        ));

        $this->assertSame($compte->id, $livreur->fresh()->user_id);
        $this->assertSame($compte->id, $parrain->fresh()->user_id);
        $this->assertTrue($this->rattachementAutomatique($parrain)->meta['automatique']);
        $this->assertSame($parrain->id, app(ActivitesCompteResolver::class)->resolve($compte->fresh())->parrain?->id);
    }

    public function test_une_personne_seulement_parrain_voit_ses_commissions_des_son_inscription(): void
    {
        $personne = Personne::resoudreOuCreer($this->org->id, ['nom_complet' => 'Awa Bah', 'telephone' => '+224666177008']);
        $parrain = $this->parrain($personne);
        $autreParrain = $this->parrain($this->personne());
        $vente = CommandeVente::factory()->create(['organization_id' => $this->org->id]);
        $this->partParrain($vente, $parrain, 25000);
        $this->partParrain($vente, $autreParrain, 99000);

        $compte = app(RegistrationService::class)->register(new RegisterData(
            telephone: '+224666177008',
            prenom: 'Awa',
            nom: 'Bah',
            password: 'Password@123',
        ));

        $this->assertSame($compte->id, $parrain->fresh()->user_id);
        $this->assertSame($this->org->id, $compte->fresh()->organization_id);
        $this->assertSame(
            'Même numéro de téléphone que le compte (numéro non vérifié).',
            $this->rattachementAutomatique($parrain)->meta['preuve'],
        );

        Sanctum::actingAs($compte->fresh(), ['*']);
        $lignes = $this->getJson(route('client.commissions.mine'))->assertOk()->json('data');
        $this->assertCount(1, $lignes);
        $this->assertSame('parrain', $lignes[0]['activite']);
        $this->assertEquals(25000, $lignes[0]['montant_a_payer']);
    }

    public function test_un_compte_a_valider_par_e_mail_recupere_sa_fiche_parrain_a_l_activation(): void
    {
        Mail::fake();
        $parrain = $this->parrain(Personne::resoudreOuCreer($this->org->id, ['nom_complet' => 'Awa Bah', 'telephone' => '+224666177009']));
        $service = app(RegistrationService::class);

        $compte = $service->register(new RegisterData(
            telephone: '+224666177009',
            prenom: 'Awa',
            nom: 'Bah',
            password: 'Password@123',
            email: 'awa.bah@example.test',
        ));
        $this->assertNull($parrain->fresh()->user_id);

        $service->verifyEmail(UserAuthIdentity::where('user_id', $compte->id)->where('type', UserAuthIdentity::TYPE_EMAIL)->value('verification_token'));

        $this->assertSame($compte->id, $parrain->fresh()->user_id);
    }

    public function test_un_parrain_cree_avec_le_numero_d_un_compte_existant_lui_est_relie(): void
    {
        $telephone = '+224620998877';
        $compte = $this->compte(Personne::factory()->create(['organization_id' => null, 'telephone' => $telephone]));

        $parrain = $this->parrain($this->personne(['telephone' => $telephone]));

        $this->assertSame($compte->id, $parrain->fresh()->user_id);
        $this->assertSame('fiche', $this->rattachementAutomatique($parrain)->meta['source']);
    }

    public function test_un_parrain_relie_par_numero_entraine_les_autres_fiches_de_sa_personne(): void
    {
        $telephone = '+224620000107';
        $compte = $this->compte(Personne::factory()->create(['organization_id' => null, 'telephone' => $telephone]));
        $personne = $this->personne(['telephone' => $telephone]);
        $consultant = Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'type' => PrestataireType::CONSULTANT->value]);
        $this->assertNull($consultant->fresh()->user_id);

        $parrain = $this->parrain($personne);

        $this->assertSame($compte->id, $parrain->fresh()->user_id);
        $this->assertSame($compte->id, $consultant->fresh()->user_id);
        $this->assertSame('Même fiche Personne que sa fiche parrain, déjà reliée au compte.', $this->rattachementAutomatique($consultant)->meta['preuve']);
    }

    public function test_un_numero_corrige_sur_la_fiche_ou_le_compte_relie_le_parrain(): void
    {
        $compte = $this->compte(Personne::factory()->create(['organization_id' => null]));
        $personne = $this->personne();
        $parrain = $this->parrain($personne);
        $this->assertNull($parrain->fresh()->user_id);

        $personne->update([
            'telephone' => $compte->telephone,
            'telephone_normalise' => Personne::normaliserTelephone($compte->telephone),
        ]);
        $this->assertSame($compte->id, $parrain->fresh()->user_id);

        $autreCompte = $this->compte();
        $autrePersonne = $this->personne(['telephone' => '+224620112233']);
        $autreParrain = $this->parrain($autrePersonne);
        $this->assertNull($autreParrain->fresh()->user_id);

        UserAuthIdentity::where('user_id', $autreCompte->id)->where('type', UserAuthIdentity::TYPE_TELEPHONE)->first()
            ->update(['value' => '+224620112233', 'normalized_value' => Personne::normaliserTelephone('+224620112233')]);
        $this->assertSame($autreCompte->id, $autreParrain->fresh()->user_id);
    }

    public function test_une_activite_reliee_a_la_connexion_entraine_les_autres_fiches_de_la_personne(): void
    {
        $compte = $this->compte();
        $personne = $this->personne();
        $livreur = $this->livreur($personne);
        $parrain = $this->parrain($personne);
        $this->assertNull($parrain->fresh()->user_id);

        // Comme lierCompteParTelephone() à la connexion.
        $livreur->update(['user_id' => $compte->id]);

        $this->assertSame($compte->id, $parrain->fresh()->user_id);
        $this->assertSame('activite_reliee', $this->rattachementAutomatique($parrain)->meta['source']);
    }

    public function test_une_fiche_livreur_creee_pour_un_compte_existant_lui_est_reliee_avec_son_role(): void
    {
        $personne = $this->personne();
        $compte = $this->compte($personne);

        $livreur = $this->livreur($personne);

        $this->assertSame($compte->id, $livreur->fresh()->user_id);
        $this->assertTrue($compte->fresh()->hasRole('livreur'));
    }

    public function test_consultant_personne_physique_et_interlocuteur_sont_relies(): void
    {
        $personne = $this->personne();
        $compte = $this->compte($personne);
        $consultant = Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'type' => PrestataireType::CONSULTANT->value]);
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'SOGECO']);
        $interlocuteur = EntrepriseInterlocuteur::create([
            'organization_id' => $this->org->id,
            'entreprise_tierce_id' => $entreprise->id,
            'personne_id' => $personne->id,
            'acces_commissions' => true,
        ]);

        $this->assertSame($compte->id, $consultant->fresh()->user_id);
        $this->assertSame($compte->id, $interlocuteur->fresh()->user_id);
    }

    public function test_une_entreprise_ou_un_prestataire_non_consultant_n_est_jamais_relie(): void
    {
        $personne = $this->personne();
        $this->compte($personne);
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'ABC']);

        $societe = Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'entreprise_tierce_id' => $entreprise->id, 'type' => PrestataireType::CONSULTANT->value]);
        $autre = Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'type' => PrestataireType::MACHINISTE->value]);

        $this->assertNull($societe->fresh()->user_id);
        $this->assertNull($autre->fresh()->user_id);
    }

    // ── Ce qui reste manuel ──────────────────────────────────────────────────

    public function test_un_numero_identique_ne_relie_que_le_parrain(): void
    {
        $telephone = '+224620998877';
        $this->compte(Personne::factory()->create(['organization_id' => null, 'telephone' => $telephone]));
        $personne = $this->personne(['telephone' => $telephone]);
        $consultant = Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'type' => PrestataireType::CONSULTANT->value]);
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'SOGECO']);
        $interlocuteur = EntrepriseInterlocuteur::create([
            'organization_id' => $this->org->id,
            'entreprise_tierce_id' => $entreprise->id,
            'personne_id' => $personne->id,
            'acces_commissions' => true,
        ]);

        foreach ([$consultant, $interlocuteur] as $fiche) {
            $this->assertNull($fiche->fresh()->user_id);
            $analyse = $this->service->analyser($fiche->fresh());
            $this->assertSame(StatutCorrespondanceCompte::AMBIGUE, $analyse->statut);
            $this->assertSame('Seul le numéro de téléphone correspond.', $analyse->motif);
        }
    }

    public function test_les_garde_fous_s_appliquent_aussi_au_numero_identique(): void
    {
        // Compte désactivé.
        $numeroInactif = '+224620000101';
        $this->compte(Personne::factory()->create(['organization_id' => null, 'telephone' => $numeroInactif]), ['is_active' => false]);
        $this->assertNull($this->parrain($this->personne(['telephone' => $numeroInactif]))->fresh()->user_id);

        // Compte d'une autre organisation.
        $numeroAutreOrg = '+224620000102';
        $this->compte(Personne::factory()->create(['organization_id' => null, 'telephone' => $numeroAutreOrg]), ['organization_id' => Organization::factory()->create()->id]);
        $autreOrg = $this->parrain($this->personne(['telephone' => $numeroAutreOrg]));
        $this->assertNull($autreOrg->fresh()->user_id);
        $this->assertSame('Ce compte appartient à une autre organisation.', $this->service->analyser($autreOrg->fresh())->motif);

        // Compte déjà relié à un autre parrain.
        $numeroDejaParrain = '+224620000103';
        $personneDuCompte = Personne::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224620000104']);
        $compte = $this->compte($personneDuCompte);
        $this->assertSame($compte->id, $this->parrain($personneDuCompte)->fresh()->user_id);
        UserAuthIdentity::where('user_id', $compte->id)->where('type', UserAuthIdentity::TYPE_TELEPHONE)->first()
            ->update(['value' => $numeroDejaParrain, 'normalized_value' => Personne::normaliserTelephone($numeroDejaParrain)]);
        $second = $this->parrain($this->personne(['telephone' => $numeroDejaParrain]));
        $this->assertNull($second->fresh()->user_id);
        $this->assertSame('Ce compte est déjà rattaché à un autre parrain.', $this->service->analyser($second->fresh())->motif);
    }

    public function test_un_detachement_bloque_aussi_le_rattachement_par_numero(): void
    {
        $telephone = '+224620000105';
        $compte = $this->compte(Personne::factory()->create(['organization_id' => null, 'telephone' => $telephone]));
        $parrain = $this->parrain($this->personne(['telephone' => $telephone]));
        $this->assertSame($compte->id, $parrain->fresh()->user_id);

        app(RattachementCompteService::class)->detacher($parrain->fresh(), User::factory()->create(['organization_id' => $this->org->id]));
        $this->service->rattacherCompte($compte->fresh(), 'compte');

        $this->assertNull($parrain->fresh()->user_id);
        $this->assertSame('Un responsable a détaché ce compte de la fiche.', $this->service->analyser($parrain->fresh())->motif);
    }

    public function test_un_compte_sans_organisation_n_en_choisit_pas_une_au_hasard(): void
    {
        $telephone = '+224620000106';
        $autreOrg = Organization::factory()->create();
        $ici = $this->parrain($this->personne(['telephone' => $telephone]));
        $ailleurs = Parrain::create([
            'organization_id' => $autreOrg->id,
            'personne_id' => Personne::factory()->create(['organization_id' => $autreOrg->id, 'telephone' => $telephone])->id,
            'is_active' => true,
        ]);

        $compte = app(RegistrationService::class)->register(new RegisterData(
            telephone: $telephone,
            prenom: 'Awa',
            nom: 'Bah',
            password: 'Password@123',
        ));

        $this->assertNull($ici->fresh()->user_id);
        $this->assertNull($ailleurs->fresh()->user_id);
        $this->assertNull($compte->fresh()->organization_id);
        $this->assertSame('Ce numéro figure sur des fiches parrain de plusieurs organisations.', $this->service->analyser($ici->fresh())->motif);
    }

    public function test_plusieurs_comptes_pour_la_meme_personne_ne_sont_jamais_departages(): void
    {
        $personne = $this->personne();
        $this->compte($personne);
        $this->compte()->update(['personne_id' => $personne->id]);

        $parrain = $this->parrain($personne);

        $this->assertNull($parrain->fresh()->user_id);
        $analyse = $this->service->analyser($parrain->fresh());
        $this->assertSame(StatutCorrespondanceCompte::AMBIGUE, $analyse->statut);
        $this->assertCount(2, $analyse->candidats);
    }

    public function test_un_detachement_par_un_responsable_n_est_jamais_annule_automatiquement(): void
    {
        $personne = $this->personne();
        $compte = $this->compte($personne);
        $parrain = $this->parrain($personne);
        $this->assertSame($compte->id, $parrain->fresh()->user_id);

        app(RattachementCompteService::class)->detacher($parrain->fresh(), User::factory()->create(['organization_id' => $this->org->id]));
        $this->assertSame(0, $this->service->rattacherPersonne($personne->id, 'fiche'));

        $this->assertNull($parrain->fresh()->user_id);
        $this->assertSame('Un responsable a détaché ce compte de la fiche.', $this->service->analyser($parrain->fresh())->motif);
    }

    public function test_compte_desactive_autre_organisation_ou_deja_parrain_restent_manuels(): void
    {
        $desactive = $this->personne();
        $this->compte($desactive, ['is_active' => false]);
        $this->assertNull($this->parrain($desactive)->fresh()->user_id);

        $autreOrg = $this->personne();
        $this->compte($autreOrg, ['organization_id' => Organization::factory()->create()->id]);
        $this->assertNull($this->parrain($autreOrg)->fresh()->user_id);

        $personne = $this->personne();
        $compte = $this->compte($personne);
        $premier = $this->parrain($personne);
        $this->assertSame($compte->id, $premier->fresh()->user_id);
        $secondePersonne = $this->personne();
        $this->livreur($secondePersonne, ['user_id' => $compte->id]);
        $second = $this->parrain($secondePersonne);
        $this->assertNull($second->fresh()->user_id);
        $this->assertSame('Ce compte est déjà rattaché à un autre parrain.', $this->service->analyser($second->fresh())->motif);
    }

    // ── Régularisation de l'existant ─────────────────────────────────────────

    public function test_la_simulation_n_ecrit_rien_et_l_application_ne_relie_que_le_certain_une_seule_fois(): void
    {
        $personne = $this->personne();
        $certain = $this->parrain($personne);
        $livreur = $this->livreur($personne);
        $compte = $this->compte();
        $this->donnerPersonneSansEvenement($compte, $personne);

        $partagee = $this->personne();
        $ambigu = $this->parrain($partagee);
        $this->donnerPersonneSansEvenement($this->compte(), $partagee);
        $this->donnerPersonneSansEvenement($this->compte(), $partagee);
        $sansCompte = $this->parrain($this->personne());
        $tracesAvant = AuditLog::count();

        $this->artisan('comptes:regulariser-rattachements')
            ->expectsOutputToContain('Mode SIMULATION')
            ->expectsOutputToContain('2 rattachement(s) seraient effectués')
            ->assertSuccessful();
        $this->assertNull($certain->fresh()->user_id);
        $this->assertNull($livreur->fresh()->user_id);
        $this->assertSame($tracesAvant, AuditLog::count());

        $this->artisan('comptes:regulariser-rattachements', ['--apply' => true, '--force' => true])
            ->expectsOutputToContain('2 rattachement(s) effectué(s)')
            ->assertSuccessful();
        $this->assertSame($compte->id, $certain->fresh()->user_id);
        $this->assertSame($compte->id, $livreur->fresh()->user_id);
        $this->assertNull($ambigu->fresh()->user_id);
        $this->assertNull($sansCompte->fresh()->user_id);
        $this->assertSame('regularisation', $this->rattachementAutomatique($certain)->meta['source']);
        $tracesApres = AuditLog::count();

        $this->artisan('comptes:regulariser-rattachements', ['--apply' => true, '--force' => true])
            ->expectsOutputToContain('Aucun rattachement certain')
            ->assertSuccessful();
        $this->assertSame($tracesApres, AuditLog::count());
    }

    public function test_l_application_sans_confirmation_n_ecrit_rien(): void
    {
        $personne = $this->personne();
        $parrain = $this->parrain($personne);
        $this->donnerPersonneSansEvenement($this->compte(), $personne);

        $this->artisan('comptes:regulariser-rattachements', ['--apply' => true])
            ->expectsConfirmation('1 fiche(s) vont être reliées à un compte. Une sauvegarde de la base a-t-elle été faite, et continuer ?', 'no')
            ->assertFailed();

        $this->assertNull($parrain->fresh()->user_id);
    }

    public function test_la_regularisation_reste_dans_l_organisation_demandee(): void
    {
        $personne = $this->personne();
        $parrain = $this->parrain($personne);
        $this->donnerPersonneSansEvenement($this->compte(), $personne);
        $autre = Organization::factory()->create();

        $this->artisan('comptes:regulariser-rattachements', ['--organization' => [$autre->id], '--apply' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertNull($parrain->fresh()->user_id);
    }
}
