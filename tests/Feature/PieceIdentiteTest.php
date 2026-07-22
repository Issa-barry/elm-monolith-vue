<?php

namespace Tests\Feature;

use App\Enums\StatutVerificationPieceIdentite;
use App\Enums\TypePieceIdentite;
use App\Models\Client;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\PieceIdentite;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PieceIdentiteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private Site $site;

    private Employe $employe;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('pieces_identite');

        $this->org = Organization::factory()->create();
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dépôt', 'type' => 'depot']);
        $this->user = $this->makeUserInOrg($this->org, [
            'pieces-identite.create', 'pieces-identite.read', 'pieces-identite.update',
            'pieces-identite.delete', 'pieces-identite.download', 'pieces-identite.valider', 'pieces-identite.rejeter',
        ], $this->site);
        $this->employe = Employe::factory()->create(['organization_id' => $this->org->id]);
    }

    private function makeUserInOrg(Organization $org, array $permissions, ?Site $site = null): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $site ??= Site::create(['organization_id' => $org->id, 'nom' => 'Dépôt', 'type' => 'depot']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permissions);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'type_piece' => TypePieceIdentite::CNI->value,
            'numero' => 'A1234567',
            'pays_delivrance' => 'GN',
            'date_delivrance' => now()->subYear()->toDateString(),
            'date_expiration' => now()->addYears(5)->toDateString(),
            'recto' => UploadedFile::fake()->image('recto.jpg'),
        ], $overrides);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_piece_for_employe(): void
    {
        $this->actingAs($this->user)
            ->post(route('pieces-identite.store', $this->employe), $this->validPayload())
            ->assertRedirect(route('employes.edit', $this->employe));

        $this->assertDatabaseHas('pieces_identite', [
            'identifiable_type' => 'employe',
            'identifiable_id' => $this->employe->id,
            'type_piece' => 'cni',
            'statut_verification' => 'en_attente',
            'est_active' => true,
        ]);
    }

    public function test_store_copies_organization_id_from_employe(): void
    {
        $this->actingAs($this->user)
            ->post(route('pieces-identite.store', $this->employe), $this->validPayload());

        $piece = PieceIdentite::firstOrFail();
        $this->assertSame($this->employe->organization_id, $piece->organization_id);
    }

    public function test_store_ignores_client_supplied_organization_id(): void
    {
        $otherOrg = Organization::factory()->create();

        $this->actingAs($this->user)
            ->post(route('pieces-identite.store', $this->employe), $this->validPayload([
                'organization_id' => $otherOrg->id,
            ]));

        $piece = PieceIdentite::firstOrFail();
        $this->assertSame($this->employe->organization_id, $piece->organization_id);
        $this->assertNotSame($otherOrg->id, $piece->organization_id);
    }

    public function test_store_rejects_invalid_file_type(): void
    {
        $this->actingAs($this->user)
            ->post(route('pieces-identite.store', $this->employe), $this->validPayload([
                'recto' => UploadedFile::fake()->create('recto.exe', 100),
            ]))
            ->assertSessionHasErrors('recto');
    }

    public function test_store_rejects_file_over_max_size(): void
    {
        $this->actingAs($this->user)
            ->post(route('pieces-identite.store', $this->employe), $this->validPayload([
                'recto' => UploadedFile::fake()->create('recto.pdf', 5121, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('recto');
    }

    public function test_store_deactivates_previous_piece_of_same_type(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->employe), $this->validPayload());
        $first = PieceIdentite::firstOrFail();

        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->employe), $this->validPayload());

        $this->assertFalse($first->fresh()->est_active);
        $this->assertSame(2, PieceIdentite::count());
    }

    public function test_store_returns_403_for_employe_of_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherEmploye = Employe::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->post(route('pieces-identite.store', $otherEmploye), $this->validPayload())
            ->assertStatus(403);
    }

    public function test_cannot_attach_piece_to_entity_other_than_employe(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->expectException(InvalidArgumentException::class);

        $piece = new PieceIdentite([
            'organization_id' => $this->org->id,
            'type_piece' => TypePieceIdentite::CNI->value,
            'statut_verification' => StatutVerificationPieceIdentite::EN_ATTENTE->value,
        ]);
        $piece->identifiable()->associate($client);
        $piece->save();
    }

    // ── téléchargement ────────────────────────────────────────────────────────

    public function test_download_allowed_with_permission(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->employe), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();

        $this->actingAs($this->user)
            ->get(route('pieces-identite.fichier', [$piece, 'recto']))
            ->assertStatus(200);
    }

    public function test_download_forbidden_without_permission(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->employe), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();

        $userSansDroit = $this->makeUserInOrg($this->org, ['pieces-identite.read']);

        $this->actingAs($userSansDroit)
            ->get(route('pieces-identite.fichier', [$piece, 'recto']))
            ->assertStatus(403);
    }

    public function test_download_forbidden_for_piece_of_other_organization(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->employe), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();

        $otherOrg = Organization::factory()->create();
        $otherUser = $this->makeUserInOrg($otherOrg, ['pieces-identite.download']);

        $this->actingAs($otherUser)
            ->get(route('pieces-identite.fichier', [$piece, 'recto']))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_replaces_recto_and_deletes_old_file(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->employe), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();
        $oldPath = $piece->recto_path;

        Storage::disk('pieces_identite')->assertExists($oldPath);

        $this->actingAs($this->user)
            ->put(route('pieces-identite.update', $piece), [
                'type_piece' => 'cni',
                'numero' => $piece->numero,
                'recto' => UploadedFile::fake()->image('nouveau-recto.jpg'),
            ])
            ->assertRedirect(route('employes.edit', $this->employe));

        $piece->refresh();
        $this->assertNotSame($oldPath, $piece->recto_path);
        Storage::disk('pieces_identite')->assertMissing($oldPath);
        Storage::disk('pieces_identite')->assertExists($piece->recto_path);
    }

    public function test_update_resets_status_to_en_attente(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->employe), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();

        $this->actingAs($this->user)->post(route('pieces-identite.valider', $piece));
        $this->assertSame('validee', $piece->fresh()->statut_verification->value);

        $this->actingAs($this->user)->put(route('pieces-identite.update', $piece), [
            'type_piece' => 'cni',
            'numero' => '999999',
        ]);

        $fresh = $piece->fresh();
        $this->assertSame('en_attente', $fresh->statut_verification->value);
        $this->assertNull($fresh->verifiee_par);
        $this->assertNull($fresh->verifiee_le);
    }

    // ── validation / rejet ───────────────────────────────────────────────────

    public function test_valider_sets_statut_and_verifier(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->employe), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();

        $this->actingAs($this->user)
            ->post(route('pieces-identite.valider', $piece))
            ->assertRedirect();

        $fresh = $piece->fresh();
        $this->assertSame('validee', $fresh->statut_verification->value);
        $this->assertSame($this->user->id, $fresh->verifiee_par);
        $this->assertNotNull($fresh->verifiee_le);
    }

    public function test_rejeter_requires_motif(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->employe), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();

        $this->actingAs($this->user)
            ->post(route('pieces-identite.rejeter', $piece), [])
            ->assertSessionHasErrors('motif_rejet');
    }

    public function test_rejeter_sets_statut_and_motif(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->employe), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();

        $this->actingAs($this->user)
            ->post(route('pieces-identite.rejeter', $piece), ['motif_rejet' => 'Photo illisible'])
            ->assertRedirect();

        $fresh = $piece->fresh();
        $this->assertSame('rejetee', $fresh->statut_verification->value);
        $this->assertSame('Photo illisible', $fresh->motif_rejet);
    }

    // ── suppression ───────────────────────────────────────────────────────────

    public function test_destroy_soft_deletes_and_keeps_files(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->employe), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();
        $path = $piece->recto_path;

        $this->actingAs($this->user)
            ->delete(route('pieces-identite.destroy', $piece))
            ->assertRedirect(route('employes.edit', $this->employe));

        $this->assertSoftDeleted('pieces_identite', ['id' => $piece->id]);
        Storage::disk('pieces_identite')->assertExists($path);
    }

    public function test_force_delete_removes_files_from_disk(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->employe), $this->validPayload([
            'verso' => UploadedFile::fake()->image('verso.jpg'),
        ]));
        $piece = PieceIdentite::firstOrFail();
        $rectoPath = $piece->recto_path;
        $versoPath = $piece->verso_path;

        $piece->forceDelete();

        Storage::disk('pieces_identite')->assertMissing($rectoPath);
        Storage::disk('pieces_identite')->assertMissing($versoPath);
    }

    // ── hasValidIdentityDocument ─────────────────────────────────────────────

    public function test_employe_has_valid_identity_document_only_when_validated_and_not_expired(): void
    {
        $this->assertFalse($this->employe->hasValidIdentityDocument());

        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->employe), $this->validPayload());
        $this->assertFalse($this->employe->hasValidIdentityDocument());

        $piece = PieceIdentite::firstOrFail();
        $this->actingAs($this->user)->post(route('pieces-identite.valider', $piece));

        $this->assertTrue($this->employe->hasValidIdentityDocument());
    }
}
