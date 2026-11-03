<?php

namespace Tests\Feature\Api\Client;

use App\Mail\ConfirmationEmailProfilMail;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\User;
use App\Services\Client\ChangementEmailProfilService;
use App\Services\Client\ClientIdentityResolver;
use App\Support\Auth\AccountEligibility;
use App\Support\Auth\AccountStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

class UpdateProfileControllerTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    public function test_proprietaire_can_update_their_localisation(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org, [], ['ville' => 'Conakry', 'adresse' => null]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), [
            'pays' => 'Guinée',
            'code_pays' => 'GN',
            'ville' => 'Kindia',
            'adresse' => 'Quartier Manquépas',
        ])
            ->assertOk()
            ->assertJsonPath('profile.localisation.ville', 'Kindia')
            ->assertJsonPath('profile.localisation.adresse', 'Quartier Manquépas');

        $this->assertSame('Kindia', $user->fresh()->proprietaire->personne->ville);
    }

    /**
     * Remplace la règle du 26/08 « identité réservée au back-office » (ADR 0051) : seuls le
     * téléphone et ce qui le recompose restent intouchables.
     */
    public function test_telephone_and_phone_country_fields_are_never_modified(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org, [], [
            'telephone' => '+224620000200',
            'code_pays' => 'GN',
            'code_phone_pays' => '+224',
        ]);
        $telephoneCompte = $user->telephone;

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), [
            'telephone' => '+224699999999',
            'code_pays' => 'FR',
            'code_phone_pays' => '+33',
            'ville' => 'Labé',
        ])->assertOk();

        $personne = $user->fresh()->proprietaire->fresh()->personne;
        $this->assertSame('+224620000200', $personne->telephone);
        $this->assertSame('GN', $personne->code_pays);
        $this->assertSame('+224', $personne->code_phone_pays);
        $this->assertSame('Labé', $personne->ville);
        $this->assertSame($telephoneCompte, $user->fresh()->telephone);
    }

    #[DataProvider('typesDeCompte')]
    public function test_each_account_type_updates_its_identity_but_never_its_telephone(string $type): void
    {
        $org = Organization::factory()->create();
        $user = match ($type) {
            'proprietaire' => $this->makeProprietaireUser($org),
            'livreur' => $this->makeLivreurUser($org),
            'client' => $this->makeClientUser($org),
        };
        $fiche = $user->{$type};
        $telephoneCompte = $user->telephone;
        $telephonePersonne = $user->personne->telephone;
        $telephoneFiche = $fiche->telephone;

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), [
            'prenom' => 'Moussa',
            'nom' => 'Camara',
            'pays' => 'Sénégal',
            'telephone' => '+33699999999',
            'code_pays' => 'FR',
            'code_phone_pays' => '+33',
        ])
            ->assertOk()
            ->assertJsonPath('profile.type', $type)
            ->assertJsonPath('profile.identite.prenom', 'Moussa')
            ->assertJsonPath('profile.identite.nom', 'CAMARA')
            ->assertJsonPath('profile.localisation.pays', 'Sénégal');

        $user = User::find($user->id);
        $this->assertSame($telephoneCompte, $user->telephone);
        $this->assertSame($telephonePersonne, $user->personne->telephone);
        $this->assertSame($telephoneFiche, $fiche->fresh()->telephone);
        $this->assertNotSame('FR', $user->personne->code_pays);
    }

    /** @return array<string, array{string}> */
    public static function typesDeCompte(): array
    {
        return [
            'propriétaire' => ['proprietaire'],
            'livreur' => ['livreur'],
            'client' => ['client'],
        ];
    }

    private function makeClientUser(Organization $org): User
    {
        $this->ensureClientRoles();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('client');
        Client::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'personne_id' => $user->personne_id,
        ]);

        return $user;
    }

    #[DataProvider('champsMalFormes')]
    public function test_malformed_identity_fields_are_refused_cleanly(string $champ): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), [$champ => ['a@example.com']])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$champ]);

        Mail::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function champsMalFormes(): array
    {
        return ['prénom' => ['prenom'], 'nom' => ['nom'], 'e-mail' => ['email'], 'pays' => ['pays']];
    }

    public function test_email_of_another_client_of_the_organization_is_refused(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        Client::factory()->create(['organization_id' => $org->id, 'email' => 'fiche@example.com']);
        $user = $this->makeClientUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['email' => 'fiche@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        Mail::assertNothingSent();
    }

    /**
     * Retour à l'ancienne adresse : un lien émis avant ce retour ne doit pas redevenir valable,
     * sinon une boîte que le titulaire vient de quitter pourrait reprendre le compte.
     */
    public function test_old_link_stays_dead_after_returning_to_the_previous_address(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org, ['email' => 'ancienne@example.com']);

        $lienInitial = $this->lienConfirmation($user, 'quittee@example.com');
        $this->travel(1)->minutes();
        $this->get($lienInitial)->assertOk();

        $user = User::find($user->id);
        $lienRetour = $this->lienConfirmation($user, 'ancienne@example.com');
        $this->travel(1)->minutes();
        $this->get($lienRetour)->assertOk();
        $this->travel(1)->minutes();

        $this->get($lienInitial)->assertStatus(404);
        $this->assertSame('ancienne@example.com', User::find($user->id)->email);
    }

    public function test_proprietaire_can_update_prenom_and_nom_with_normalisation(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['prenom' => 'ya moussa', 'nom' => 'camara'])
            ->assertOk()
            ->assertJsonPath('profile.identite.prenom', 'Ya Moussa')
            ->assertJsonPath('profile.identite.nom', 'CAMARA')
            ->assertJsonPath('email_confirmation', null);

        $this->getJson(route('api.auth.me'))
            ->assertJsonPath('prenom', 'Ya Moussa')
            ->assertJsonPath('nom', 'CAMARA')
            ->assertJsonPath('nom_affichage', 'Ya Moussa CAMARA');
    }

    public function test_compte_client_et_livreur_updates_every_identity_of_the_account(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org);
        $user->assignRole('client');
        $personneClient = Personne::factory()->create(['organization_id' => $org->id]);
        $client = Client::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'personne_id' => $personneClient->id,
            'nom_complet' => 'Camara Ya Moussa',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['prenom' => 'Moussa', 'nom' => 'Camara'])->assertOk();

        $this->assertSame('CAMARA', $user->fresh()->personne->nom);
        $this->assertSame('CAMARA', $user->fresh()->livreur->personne->nom);
        $this->assertSame('CAMARA', $personneClient->fresh()->nom);
        $this->assertSame('Moussa', $client->fresh()->prenom);
        // Libellé géré au back-office : jamais réécrit par le titulaire du compte.
        $this->assertSame('Camara Ya Moussa', $client->fresh()->nom_complet);
    }

    public function test_another_account_is_never_modified(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $autre = $this->makeProprietaireUser($org, [], ['nom' => 'SIDIBÉ']);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['nom' => 'Camara', 'user_id' => $autre->id])->assertOk();

        $this->assertSame('SIDIBÉ', $autre->fresh()->proprietaire->personne->nom);
    }

    public function test_prenom_and_nom_cannot_be_emptied(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['prenom' => '', 'nom' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['prenom', 'nom']);
    }

    public function test_pays_is_selected_from_the_published_list(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $liste = $this->getJson(route('client.profile.mine'))->assertOk()->json('pays_disponibles');
        $this->assertContains('Sénégal', $liste);

        $this->patchJson(route('client.profile.update'), ['pays' => 'Atlantide'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pays']);

        $this->patchJson(route('client.profile.update'), ['pays' => 'Sénégal'])
            ->assertOk()
            ->assertJsonPath('profile.localisation.pays', 'Sénégal');
    }

    public function test_changing_email_changes_nothing_until_the_link_is_opened(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org, ['email' => 'ancienne@example.com']);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['email' => 'Nouvelle@Example.com'])
            ->assertOk()
            ->assertJsonPath('email_confirmation.adresse', 'nouvelle@example.com')
            ->assertJsonPath('email_confirmation.envoyee', true)
            ->assertJsonPath('user.email', 'ancienne@example.com');

        Mail::assertSent(ConfirmationEmailProfilMail::class, function ($mail) {
            $this->assertStringContainsString('Confirmer mon adresse e-mail', $mail->render());

            return $mail->hasTo('nouvelle@example.com');
        });

        // Le compte reste éligible à la connexion pendant l'attente.
        $user = $user->fresh();
        $this->assertSame('ancienne@example.com', $user->email);
        $this->assertSame('ancienne@example.com', $user->personne->email);
        $this->assertSame(AccountStatus::Ok, AccountEligibility::status($user));
    }

    public function test_opening_the_link_applies_the_email_everywhere_and_verifies_it(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org, ['email' => 'ancienne@example.com']);
        $user->assignRole('client');
        $client = Client::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'email' => null]);

        $this->get($this->lienConfirmation($user, 'nouvelle@example.com'))
            ->assertOk()
            ->assertSee('Adresse e-mail confirmée');

        $user = User::find($user->id);
        $this->assertSame('nouvelle@example.com', $user->email);
        $this->assertSame('nouvelle@example.com', $user->personne->email);
        $this->assertSame('nouvelle@example.com', $client->fresh()->email);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(AccountStatus::Ok, AccountEligibility::status($user));
    }

    public function test_link_creates_the_email_identity_of_a_phone_only_account(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org);
        $this->assertNull($user->email);

        $this->get($this->lienConfirmation($user, 'premiere@example.com'))->assertOk();

        $user = User::find($user->id);
        $this->assertSame('premiere@example.com', $user->email);
        $this->assertTrue($user->emailIdentity()->isVerified());
    }

    public function test_link_is_refused_when_tampered_expired_replayed_or_address_taken(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org, ['email' => 'ancienne@example.com']);

        $lien = $this->lienConfirmation($user, 'nouvelle@example.com');
        $this->get(str_replace('nouvelle%40example.com', 'pirate%40example.com', $lien))->assertStatus(404);

        $this->travel(25)->hours();
        $this->get($lien)->assertStatus(410);
        $this->travelBack();

        $lienPris = $this->lienConfirmation($user, 'prise@example.com');
        $this->makeLivreurUser($org, ['email' => 'prise@example.com']);
        $this->get($lienPris)->assertStatus(409);
        $this->assertSame('ancienne@example.com', User::find($user->id)->email);

        $this->get($lien)->assertOk();
        // Rejeu : l'adresse a changé, le même lien (ou un lien plus ancien) ne s'applique plus.
        $this->get($lien)->assertStatus(404);
    }

    public function test_email_already_used_is_refused_without_saving_the_rest(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $this->makeLivreurUser($org, ['email' => 'prise@example.com']);
        $user = $this->makeProprietaireUser($org, [], ['nom' => 'SIDIBÉ']);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['nom' => 'Camara', 'email' => 'PRISE@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertSame('SIDIBÉ', $user->fresh()->proprietaire->personne->nom);
        Mail::assertNothingSent();
    }

    public function test_email_cannot_be_emptied_or_invalid(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org, ['email' => 'ancienne@example.com']);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['email' => null])->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->patchJson(route('client.profile.update'), ['email' => 'pas-une-adresse'])->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->assertSame('ancienne@example.com', $user->fresh()->email);
    }

    public function test_sending_the_current_email_again_sends_nothing(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org, ['email' => 'ancienne@example.com']);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['email' => 'ANCIENNE@example.com'])
            ->assertOk()
            ->assertJsonPath('email_confirmation', null);

        Mail::assertNothingSent();
    }

    public function test_mail_failure_is_reported_and_keeps_the_other_changes(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP indisponible'));
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['nom' => 'Camara', 'email' => 'nouvelle@example.com'])
            ->assertOk()
            ->assertJsonPath('email_confirmation.envoyee', false)
            ->assertJsonPath('profile.identite.nom', 'CAMARA');
    }

    public function test_confirmation_mails_are_limited_per_hour(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        foreach (range(1, 5) as $i) {
            $this->patchJson(route('client.profile.update'), ['email' => "essai{$i}@example.com"])->assertOk();
        }

        $this->patchJson(route('client.profile.update'), ['email' => 'essai6@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        Mail::assertSent(ConfirmationEmailProfilMail::class, 5);

        $this->travel(61)->minutes();
        $this->patchJson(route('client.profile.update'), ['email' => 'essai6@example.com'])->assertOk();
    }

    public function test_identity_can_be_updated_without_a_linked_profile(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->ensureClientRoles();
        $user->assignRole('client');

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['prenom' => 'Moussa', 'nom' => 'Camara'])
            ->assertOk()
            ->assertJsonPath('profile', null);

        $this->assertSame('CAMARA', $user->fresh()->personne->nom);
    }

    private function lienConfirmation(User $user, string $email): string
    {
        Mail::fake();
        $service = app(ChangementEmailProfilService::class);
        $service->demander($user, app(ClientIdentityResolver::class)->resolve($user), $email);

        $lien = null;
        Mail::assertSent(ConfirmationEmailProfilMail::class, function ($mail) use ($email, &$lien) {
            if ($mail->hasTo($email)) {
                $lien = $mail->confirmationUrl;
            }

            return true;
        });

        return $lien;
    }

    public function test_client_can_update_their_localisation_directly(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->ensureClientRoles();
        $user->assignRole('client');
        $client = Client::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['ville' => 'Mamou'])
            ->assertOk()
            ->assertJsonPath('profile.localisation.ville', 'Mamou');

        $this->assertSame('Mamou', $client->fresh()->ville);
    }

    /**
     * Client reste un rôle porté par Personne (cf. docs/identite-client-personne.md) : la
     * localisation modifiée en self-service doit rester visible depuis un autre rôle
     * (Parrain, Propriétaire...) de la même personne physique, pas figée côté Client seul.
     */
    public function test_client_profile_update_mirrors_onto_linked_personne(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->ensureClientRoles();
        $user->assignRole('client');
        $personne = Personne::resoudreOuCreer($org->id, ['telephone' => '+224622000099', 'ville' => 'Conakry']);
        $client = Client::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'personne_id' => $personne->id,
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['ville' => 'Labé'])
            ->assertOk();

        $this->assertSame('Labé', $client->fresh()->ville);
        $this->assertSame('Labé', $personne->fresh()->ville);
    }

    public function test_returns_404_when_no_profile_is_linked(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->ensureClientRoles();
        $user->assignRole('client');

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['ville' => 'Kankan'])
            ->assertStatus(404);
    }

    public function test_requires_a_client_role(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user, ['*']);

        $this->patchJson(route('client.profile.update'), ['ville' => 'Kankan'])
            ->assertStatus(403);
    }
}
