<?php

namespace Tests\Feature;

use App\Enums\StatutVerificationPieceIdentite;
use App\Enums\TypePieceIdentite;
use App\Models\Client;
use App\Models\Organization;
use App\Models\PieceIdentite;
use App\Models\Proprietaire;
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

    private Proprietaire $proprietaire;

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
        $this->proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
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

    public function test_store_creates_piece_for_proprietaire(): void
    {
        $this->actingAs($this->user)
            ->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload())
            ->assertRedirect(route('proprietaires.show', $this->proprietaire));

        $this->assertDatabaseHas('pieces_identite', [
            'identifiable_type' => 'proprietaire',
            'identifiable_id' => $this->proprietaire->id,
            'type_piece' => 'cni',
            'statut_verification' => 'en_attente',
            'est_active' => true,
        ]);
    }

    public function test_store_generates_ulid(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());

        $piece = PieceIdentite::firstOrFail();
        $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{26}$/', $piece->id);
    }

    public function test_store_copies_organization_id_from_proprietaire(): void
    {
        $this->actingAs($this->user)
            ->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());

        $piece = PieceIdentite::firstOrFail();
        $this->assertSame($this->proprietaire->organization_id, $piece->organization_id);
    }

    public function test_store_ignores_client_supplied_organization_id(): void
    {
        $otherOrg = Organization::factory()->create();

        $this->actingAs($this->user)
            ->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload([
                'organization_id' => $otherOrg->id,
            ]));

        $piece = PieceIdentite::firstOrFail();
        $this->assertSame($this->proprietaire->organization_id, $piece->organization_id);
        $this->assertNotSame($otherOrg->id, $piece->organization_id);
    }

    public function test_store_requires_recto(): void
    {
        $payload = $this->validPayload();
        unset($payload['recto']);

        $this->actingAs($this->user)
            ->post(route('pieces-identite.store', $this->proprietaire), $payload)
            ->assertSessionHasErrors('recto');
    }

    public function test_store_allows_missing_verso(): void
    {
        $this->actingAs($this->user)
            ->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload())
            ->assertSessionDoesntHaveErrors('verso');

        $piece = PieceIdentite::firstOrFail();
        $this->assertNull($piece->verso_path);
        $this->assertNotNull($piece->recto_path);
    }

    public function test_store_rejects_invalid_file_type(): void
    {
        $this->actingAs($this->user)
            ->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload([
                'recto' => UploadedFile::fake()->create('recto.exe', 100),
            ]))
            ->assertSessionHasErrors('recto');
    }

    public function test_store_rejects_file_over_max_size(): void
    {
        $this->actingAs($this->user)
            ->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload([
                'recto' => UploadedFile::fake()->create('recto.pdf', 5121, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('recto');
    }

    public function test_store_deactivates_previous_piece_of_same_type(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());
        $first = PieceIdentite::firstOrFail();

        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());

        $this->assertFalse($first->fresh()->est_active);
        $this->assertSame(2, PieceIdentite::count());
    }

    public function test_store_returns_403_for_proprietaire_of_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherProprietaire = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->post(route('pieces-identite.store', $otherProprietaire), $this->validPayload())
            ->assertStatus(403);
    }

    public function test_cannot_attach_piece_to_entity_other_than_proprietaire(): void
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
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();

        $this->actingAs($this->user)
            ->get(route('pieces-identite.fichier', [$piece, 'recto']))
            ->assertStatus(200);
    }

    public function test_download_forbidden_without_permission(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();

        $userSansDroit = $this->makeUserInOrg($this->org, ['pieces-identite.read']);

        $this->actingAs($userSansDroit)
            ->get(route('pieces-identite.fichier', [$piece, 'recto']))
            ->assertStatus(403);
    }

    public function test_download_forbidden_for_piece_of_other_organization(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());
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
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();
        $oldPath = $piece->recto_path;

        Storage::disk('pieces_identite')->assertExists($oldPath);

        $this->actingAs($this->user)
            ->put(route('pieces-identite.update', $piece), [
                'type_piece' => 'cni',
                'numero' => $piece->numero,
                'recto' => UploadedFile::fake()->image('nouveau-recto.jpg'),
            ])
            ->assertRedirect(route('proprietaires.show', $this->proprietaire));

        $piece->refresh();
        $this->assertNotSame($oldPath, $piece->recto_path);
        Storage::disk('pieces_identite')->assertMissing($oldPath);
        Storage::disk('pieces_identite')->assertExists($piece->recto_path);
    }

    public function test_update_resets_status_to_en_attente(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());
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
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());
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
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();

        $this->actingAs($this->user)
            ->post(route('pieces-identite.rejeter', $piece), [])
            ->assertSessionHasErrors('motif_rejet');
    }

    public function test_rejeter_sets_statut_and_motif(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();

        $this->actingAs($this->user)
            ->post(route('pieces-identite.rejeter', $piece), ['motif_rejet' => 'Photo illisible'])
            ->assertRedirect();

        $fresh = $piece->fresh();
        $this->assertSame('rejetee', $fresh->statut_verification->value);
        $this->assertSame('Photo illisible', $fresh->motif_rejet);
    }

    // ── pièce active unique par type ─────────────────────────────────────────

    public function test_only_one_active_piece_per_type(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload([
            'type_piece' => TypePieceIdentite::PASSEPORT->value,
        ]));

        $this->assertSame(2, PieceIdentite::where('est_active', true)->count());

        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());

        $this->assertSame(2, PieceIdentite::where('est_active', true)->count());
        $this->assertSame(3, PieceIdentite::count());
    }

    // ── expiration ────────────────────────────────────────────────────────────

    public function test_expired_piece_is_not_considered_valid(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload([
            'date_expiration' => now()->subDay()->toDateString(),
        ]));
        $piece = PieceIdentite::firstOrFail();
        $this->actingAs($this->user)->post(route('pieces-identite.valider', $piece));

        $this->assertTrue($piece->fresh()->isExpiree());
        $this->assertFalse($this->proprietaire->fresh()->hasValidIdentityDocument());
    }

    // ── suppression ───────────────────────────────────────────────────────────

    public function test_destroy_soft_deletes_and_keeps_files(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());
        $piece = PieceIdentite::firstOrFail();
        $path = $piece->recto_path;

        $this->actingAs($this->user)
            ->delete(route('pieces-identite.destroy', $piece))
            ->assertRedirect(route('proprietaires.show', $this->proprietaire));

        $this->assertSoftDeleted('pieces_identite', ['id' => $piece->id]);
        Storage::disk('pieces_identite')->assertExists($path);
    }

    public function test_force_delete_removes_files_from_disk(): void
    {
        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload([
            'verso' => UploadedFile::fake()->image('verso.jpg'),
        ]));
        $piece = PieceIdentite::firstOrFail();
        $rectoPath = $piece->recto_path;
        $versoPath = $piece->verso_path;
        $directory = dirname($rectoPath);

        $piece->forceDelete();

        Storage::disk('pieces_identite')->assertMissing($rectoPath);
        Storage::disk('pieces_identite')->assertMissing($versoPath);
        $this->assertFalse(Storage::disk('pieces_identite')->exists($directory));
    }

    // ── hasValidIdentityDocument ─────────────────────────────────────────────

    public function test_proprietaire_has_valid_identity_document_only_when_validated_and_not_expired(): void
    {
        $this->assertFalse($this->proprietaire->hasValidIdentityDocument());

        $this->actingAs($this->user)->post(route('pieces-identite.store', $this->proprietaire), $this->validPayload());
        $this->assertFalse($this->proprietaire->hasValidIdentityDocument());

        $piece = PieceIdentite::firstOrFail();
        $this->actingAs($this->user)->post(route('pieces-identite.valider', $piece));

        $this->assertTrue($this->proprietaire->hasValidIdentityDocument());
    }
}
