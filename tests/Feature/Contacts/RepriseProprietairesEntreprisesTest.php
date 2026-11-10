<?php

namespace Tests\Feature\Contacts;

use App\Features\ModuleFeature;
use App\Models\ContactsReprise;
use App\Models\ContactsRepriseLigne;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Lot 5 du référentiel Contacts (ADR 0060, CONTACT-031 et CONTACT-032) : reprise des propriétaires
 * entreprise existants — simulation sans écriture, application journalisée et idempotente,
 * correspondances jamais reliées d'office, arbitrage explicite, rapport d'anomalies, annulation qui
 * vérifie que le rattachement n'a pas changé, désactive sans supprimer et conserve les entreprises.
 */
class RepriseProprietairesEntreprisesTest extends TestCase
{
    use RefreshDatabase;

    private const COMMANDE = 'contacts:reprendre-proprietaires-entreprises';

    private Organization $org;

    private User $acteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::VEHICULES);
        $this->acteur = User::factory()->create(['organization_id' => $this->org->id]);
        foreach (['proprietaires.update', 'contacts.update'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->acteur->givePermissionTo(['proprietaires.update', 'contacts.update']);
    }

    private function proprietaire(string $raisonSociale, array $identite = []): Proprietaire
    {
        return Proprietaire::factory()->entreprise($raisonSociale)->create(['organization_id' => $this->org->id, ...$identite]);
    }

    private function appliquer(array $options = []): ?ContactsReprise
    {
        $this->artisan(self::COMMANDE, ['--acteur' => $this->acteur->id, '--apply' => true, ...$options])->assertSuccessful();

        return ContactsReprise::where('mode', ContactsReprise::MODE_APPLICATION)->latest('id')->first();
    }

    public function test_la_simulation_n_ecrit_rien(): void
    {
        $p = $this->proprietaire('Transports Boké');

        $this->artisan(self::COMMANDE, ['--acteur' => $this->acteur->id])
            ->expectsOutputToContain('Simulation')
            ->assertSuccessful();

        $this->assertNull($p->fresh()->entreprise_tierce_id);
        $this->assertSame(0, EntrepriseTierce::count());
        $this->assertSame(0, ContactsReprise::count());
        $this->assertSame(0, EntrepriseInterlocuteur::count());
    }

    public function test_application_cree_relie_journalise_et_reste_idempotente(): void
    {
        $p = $this->proprietaire('Transports Boké');

        $reprise = $this->appliquer();

        $p->refresh();
        $this->assertNotNull($reprise);
        $this->assertNotNull($p->entreprise_tierce_id);
        $entreprise = EntrepriseTierce::findOrFail($p->entreprise_tierce_id);
        $this->assertSame('Transports Boké', $entreprise->raison_sociale);
        // Ni téléphone ni localisation déduits du contact (qui a une ville et un pays).
        $this->assertNotNull($p->personne->ville);
        foreach (['telephone', 'telephone_normalise', 'pays', 'code_pays', 'code_phone_pays', 'ville', 'quartier', 'adresse', 'latitude', 'longitude'] as $colonne) {
            $this->assertNull($entreprise->{$colonne}, "entreprises_tierces.{$colonne} ne doit pas être reprise du contact");
        }
        $ligne = ContactsRepriseLigne::where('contacts_reprise_id', $reprise->id)->firstOrFail();
        $this->assertTrue($ligne->entreprise_creee);
        $this->assertSame('absent', $ligne->interlocuteur_etat_avant);
        $this->assertTrue($ligne->principal_pose);
        // assertEquals : une colonne JSON MySQL réordonne les clés.
        $this->assertEquals(['entreprise_tierce_id' => null, 'raison_sociale' => 'Transports Boké'], $ligne->valeurs_avant);
        $this->assertDatabaseHas('entreprise_interlocuteurs', ['entreprise_tierce_id' => $entreprise->id, 'personne_id' => $p->personne_id, 'est_principal' => true]);

        $this->artisan(self::COMMANDE, ['--acteur' => $this->acteur->id, '--apply' => true])
            ->expectsOutputToContain('Aucune action à appliquer')
            ->assertSuccessful();
        $this->assertSame(1, EntrepriseTierce::count());
        $this->assertSame(1, ContactsReprise::count());
    }

    public function test_une_correspondance_n_est_jamais_reliee_d_office_puis_s_arbitre(): void
    {
        $existante = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'TRANSPORTS BOKE']);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $existante->id]);
        $relier = $this->proprietaire('Transports Boké');
        $creer = $this->proprietaire('Transports  boke');

        $this->assertNull($this->appliquer());
        $this->assertNull($relier->fresh()->entreprise_tierce_id);
        $this->assertNull($creer->fresh()->entreprise_tierce_id);

        $this->appliquer(['--relier' => ["{$relier->id}:{$existante->id}"], '--creer' => [$creer->id]]);

        $this->assertSame($existante->id, $relier->fresh()->entreprise_tierce_id);
        // D5 : la raison sociale de l'entreprise fait foi dès le lien.
        $this->assertSame('TRANSPORTS BOKE', $relier->fresh()->raison_sociale);
        $this->assertNotNull($creer->fresh()->entreprise_tierce_id);
        $this->assertNotSame($existante->id, $creer->fresh()->entreprise_tierce_id);
    }

    public function test_les_anomalies_sont_signalees_et_non_traitees(): void
    {
        $sansRaison = $this->proprietaire('');
        $sansTelephone = $this->proprietaire('Sans Téléphone SARL');
        $sansTelephone->personne->forceFill(['telephone' => null, 'telephone_normalise' => null])->save();

        $this->artisan(self::COMMANDE, ['--acteur' => $this->acteur->id])
            ->expectsOutputToContain('raison_sociale_vide')
            ->expectsOutputToContain('contact_sans_telephone')
            ->assertSuccessful();

        $this->assertNull($this->appliquer());
        $this->assertNull($sansRaison->fresh()->entreprise_tierce_id);
        $this->assertNull($sansTelephone->fresh()->entreprise_tierce_id);
    }

    public function test_annulation_simulee_puis_appliquee_sans_rien_supprimer(): void
    {
        $p = $this->proprietaire('Transports Boké');
        $reprise = $this->appliquer();
        $ligne = ContactsRepriseLigne::firstOrFail();

        $this->artisan(self::COMMANDE, ['--acteur' => $this->acteur->id, '--annuler' => $reprise->id])->assertSuccessful();
        $this->assertSame($ligne->entreprise_tierce_id, $p->fresh()->entreprise_tierce_id);

        $this->appliquer(['--annuler' => $reprise->id]);

        $p->refresh();
        $this->assertNull($p->entreprise_tierce_id);
        $this->assertSame('Transports Boké', $p->raison_sociale);
        // Entreprise créée conservée, interlocuteur créé désactivé (donc plus principal), jamais supprimé.
        $this->assertNotNull(EntrepriseTierce::find($ligne->entreprise_tierce_id));
        $interlocuteur = EntrepriseInterlocuteur::findOrFail($ligne->interlocuteur_id);
        $this->assertFalse($interlocuteur->is_active);
        $this->assertFalse($interlocuteur->est_principal);
        $this->assertSame(ContactsRepriseLigne::ANNULEE, $ligne->fresh()->annulation_statut);
        $this->assertTrue(ContactsReprise::where('mode', ContactsReprise::MODE_ANNULATION)->where('reprise_annulee_id', $reprise->id)->exists());

        // Relancer l'annulation ne fait rien de plus.
        $this->artisan(self::COMMANDE, ['--acteur' => $this->acteur->id, '--annuler' => $reprise->id, '--apply' => true])
            ->expectsOutputToContain('Rien à annuler')
            ->assertSuccessful();
    }

    public function test_annulation_refusee_si_le_rattachement_a_change_depuis(): void
    {
        $p = $this->proprietaire('Transports Boké');
        $reprise = $this->appliquer();
        $autre = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Autre']);
        $p->forceFill(['entreprise_tierce_id' => $autre->id])->save();

        $this->appliquer(['--annuler' => $reprise->id]);

        $this->assertSame($autre->id, $p->fresh()->entreprise_tierce_id);
        $this->assertSame(ContactsRepriseLigne::CONFLIT, ContactsRepriseLigne::firstOrFail()->annulation_statut);
        $this->assertTrue(EntrepriseInterlocuteur::findOrFail(ContactsRepriseLigne::firstOrFail()->interlocuteur_id)->is_active);
    }

    public function test_annulation_d_un_lien_existant_retire_seulement_le_principal_pose(): void
    {
        $existante = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Kaloum SA']);
        $p = $this->proprietaire('Ancien nom');
        $lien = EntrepriseInterlocuteur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $existante->id, 'personne_id' => $p->personne_id, 'est_principal' => false, 'is_active' => true]);
        $reprise = $this->appliquer(['--relier' => ["{$p->id}:{$existante->id}"]]);
        $this->assertTrue($lien->fresh()->est_principal);

        $this->appliquer(['--annuler' => $reprise->id]);

        $lien->refresh();
        $this->assertTrue($lien->is_active);
        $this->assertFalse($lien->est_principal);
        $this->assertSame('Ancien nom', $p->fresh()->raison_sociale);
    }

    public function test_application_refusee_sans_les_droits_et_annulation_hors_organisation_introuvable(): void
    {
        $this->proprietaire('Transports Boké');
        $sansDroit = User::factory()->create(['organization_id' => $this->org->id]);

        $this->artisan(self::COMMANDE, ['--acteur' => $sansDroit->id, '--apply' => true])->assertExitCode(Command::INVALID);
        $this->assertSame(0, EntrepriseTierce::count());

        $reprise = $this->appliquer();
        $ailleurs = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $ailleurs->givePermissionTo(['proprietaires.update', 'contacts.update']);

        $this->artisan(self::COMMANDE, ['--acteur' => $ailleurs->id, '--annuler' => $reprise->id, '--apply' => true])
            ->expectsOutputToContain('introuvable')
            ->assertExitCode(Command::INVALID);
        $this->assertNotNull(Proprietaire::firstOrFail()->entreprise_tierce_id);
    }

    public function test_le_contact_d_une_autre_organisation_est_une_anomalie(): void
    {
        $p = $this->proprietaire('Transports Boké');
        $etrangere = Personne::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $p->forceFill(['personne_id' => $etrangere->id])->save();

        $this->artisan(self::COMMANDE, ['--acteur' => $this->acteur->id])
            ->expectsOutputToContain('contact_autre_organisation')
            ->assertSuccessful();
    }
}
