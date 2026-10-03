<?php

namespace Tests\Feature;

use App\Models\CommandeVente;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\TestCase;

/**
 * Une référence Mobile Money ne sert qu'une fois par organisation, quels que soient la vente,
 * l'agence, l'agent ou l'opérateur (ADR 0014) : validation applicative + index unique en base.
 */
class EncaissementReferenceMobileMoneyUniqueTest extends TestCase
{
    use HasCaissesDediees, RefreshDatabase;

    /** Message de repli quand la facture de l'autre utilisation n'est pas identifiable. */
    private const MESSAGE = 'Cette référence Mobile Money a déjà été utilisée.';

    private const MIGRATION = '2026_10_01_100000_add_cle_reference_mobile_money_to_encaissements_ventes_table.php';

    /** @return array{org: Organization, facture: FactureVente, user: User, orange: CompteTresorerie} */
    private function contexte(?Organization $org = null): array
    {
        $org ??= Organization::factory()->create();

        Permission::firstOrCreate(['name' => 'factures.encaisser', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('factures.encaisser');

        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Site '.Str::random(4), 'type' => 'depot', 'localisation' => 'Conakry']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        $this->creerCaisseActivePour($user, $site->id);

        $facture = $this->facture($org, $site);

        return [
            'org' => $org,
            'facture' => $facture,
            'user' => $user,
            'orange' => $this->creerSupportAgence($site->id, 'mobile_money', '561100', 'orange_money'),
        ];
    }

    private function facture(Organization $org, Site $site): FactureVente
    {
        $commande = CommandeVente::factory()->create(['organization_id' => $org->id, 'total_commande' => 10_000_000]);

        return FactureVente::factory()->create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
            'site_id' => $site->id,
            'montant_net' => 10_000_000,
        ]);
    }

    /** @param  array<string, mixed>  $donnees */
    private function encaisser(User $user, FactureVente $facture, array $donnees)
    {
        return $this->actingAs($user)->post(route('encaissements.store', $facture), array_merge([
            'montant' => 1000,
            'date_encaissement' => now()->toDateString(),
        ], $donnees));
    }

    private function encaisserMobileMoney(User $user, FactureVente $facture, CompteTresorerie $support, ?string $reference)
    {
        return $this->encaisser($user, $facture, [
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $support->id,
            'reference_paiement' => $reference,
        ]);
    }

    /** @return array<string, string> */
    private function dejaUtiliseePar(FactureVente $facture): array
    {
        $this->assertNotEmpty($facture->reference);

        return [
            'reference_paiement' => "Référence déjà utilisée — facture {$facture->reference}",
            'reference_paiement_facture' => $facture->reference,
        ];
    }

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    // ── Règle ────────────────────────────────────────────────────────────────

    public function test_une_reference_deja_utilisee_est_refusee_meme_sur_une_autre_vente(): void
    {
        ['org' => $org, 'facture' => $facture, 'user' => $user, 'orange' => $orange] = $this->contexte();
        $autreVente = $this->facture($org, Site::findOrFail($facture->site_id));

        $this->encaisserMobileMoney($user, $facture, $orange, 'OM123456789')->assertSessionHasNoErrors();
        $this->encaisserMobileMoney($user, $autreVente, $orange, 'OM123456789')
            ->assertSessionHasErrors($this->dejaUtiliseePar($facture));

        $this->assertSame(1, EncaissementVente::where('reference_paiement', 'OM123456789')->count());
        $this->assertSame(0, $autreVente->encaissements()->count());
    }

    public function test_la_casse_ne_permet_pas_de_reutiliser_une_reference(): void
    {
        ['facture' => $facture, 'user' => $user, 'orange' => $orange] = $this->contexte();

        $this->encaisserMobileMoney($user, $facture, $orange, 'OM123')->assertSessionHasNoErrors();
        $this->encaisserMobileMoney($user, $facture, $orange, 'om123')
            ->assertSessionHasErrors($this->dejaUtiliseePar($facture));

        $this->assertSame(1, $facture->encaissements()->count());
    }

    public function test_les_espaces_ne_permettent_pas_de_reutiliser_une_reference(): void
    {
        ['facture' => $facture, 'user' => $user, 'orange' => $orange] = $this->contexte();

        $this->encaisserMobileMoney($user, $facture, $orange, 'OM123')->assertSessionHasNoErrors();
        $this->encaisserMobileMoney($user, $facture, $orange, '  OM123  ')
            ->assertSessionHasErrors($this->dejaUtiliseePar($facture));

        $this->assertSame(1, $facture->encaissements()->count());
    }

    public function test_la_meme_reference_chez_un_autre_operateur_est_refusee(): void
    {
        ['facture' => $facture, 'user' => $user, 'orange' => $orange] = $this->contexte();
        $kulu = $this->creerSupportAgence($facture->site_id, 'mobile_money', '561400', 'kulu');

        $this->encaisserMobileMoney($user, $facture, $orange, '123456')->assertSessionHasNoErrors();
        $this->encaisserMobileMoney($user, $facture, $kulu, '123456')
            ->assertSessionHasErrors($this->dejaUtiliseePar($facture));

        $this->assertSame(0, $facture->encaissements()->where('operateur_mobile_money', 'kulu')->count());
    }

    public function test_des_references_differentes_sont_acceptees_et_enregistrees_normalisees(): void
    {
        ['facture' => $facture, 'user' => $user, 'orange' => $orange] = $this->contexte();

        $this->encaisserMobileMoney($user, $facture, $orange, ' om-111 ')->assertSessionHasNoErrors();
        $this->encaisserMobileMoney($user, $facture, $orange, 'OM-222')->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['OM-111', 'OM-222'], $facture->encaissements()->pluck('reference_paiement')->all());
    }

    public function test_les_especes_sans_reference_sont_acceptees(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->contexte();

        $this->encaisser($user, $facture, ['mode_paiement' => 'especes'])->assertSessionHasNoErrors();
        $this->encaisser($user, $facture, ['mode_paiement' => 'especes'])->assertSessionHasNoErrors();

        $this->assertSame(2, $facture->encaissements()->whereNull('cle_reference_mobile_money')->count());
    }

    public function test_un_mobile_money_sans_reference_est_refuse(): void
    {
        ['facture' => $facture, 'user' => $user, 'orange' => $orange] = $this->contexte();

        $this->encaisserMobileMoney($user, $facture, $orange, null)->assertSessionHasErrors('reference_paiement');
        $this->encaisserMobileMoney($user, $facture, $orange, '   ')->assertSessionHasErrors('reference_paiement');

        $this->assertSame(0, $facture->encaissements()->count());
    }

    public function test_les_autres_modes_ne_sont_pas_concernes(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->contexte();
        $banque = $this->creerSupportAgence($facture->site_id, 'banque', '521000');

        for ($i = 0; $i < 2; $i++) {
            $this->encaisser($user, $facture, [
                'mode_paiement' => 'virement',
                'compte_tresorerie_id' => $banque->id,
                'reference_paiement' => 'VIR-1',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, $facture->encaissements()->where('reference_paiement', 'VIR-1')->count());
    }

    public function test_une_autre_organisation_n_est_pas_concernee(): void
    {
        ['facture' => $facture, 'user' => $user, 'orange' => $orange] = $this->contexte();
        $autre = $this->contexte();

        $this->encaisserMobileMoney($user, $facture, $orange, 'OM-999')->assertSessionHasNoErrors();
        $this->encaisserMobileMoney($autre['user'], $autre['facture'], $autre['orange'], 'OM-999')->assertSessionHasNoErrors();

        $this->assertSame(2, EncaissementVente::where('reference_paiement', 'OM-999')->count());
    }

    public function test_une_reference_redevient_libre_si_son_encaissement_est_supprime(): void
    {
        ['facture' => $facture, 'user' => $user, 'orange' => $orange] = $this->contexte();

        $this->encaisserMobileMoney($user, $facture, $orange, 'OM-ERREUR')->assertSessionHasNoErrors();
        $facture->encaissements()->firstOrFail()->delete();

        $this->encaisserMobileMoney($user, $facture->fresh(), $orange, 'OM-ERREUR')->assertSessionHasNoErrors();
    }

    // ── Protection en base ──────────────────────────────────────────────────

    public function test_la_base_refuse_un_doublon_meme_sans_passer_par_le_controleur(): void
    {
        ['facture' => $facture] = $this->contexte();
        $creer = fn (string $reference) => EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => 1000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'mobile_money',
            'operateur_mobile_money' => 'orange_money',
            'reference_paiement' => $reference,
        ]);

        $creer('OM-777');

        $this->expectException(UniqueConstraintViolationException::class);
        $creer(' om-777 ');
    }

    public function test_deux_saisies_simultanees_de_la_meme_reference_une_seule_est_enregistree(): void
    {
        ['facture' => $facture, 'user' => $user, 'orange' => $orange] = $this->contexte();

        // Un autre utilisateur enregistre la même référence APRÈS le contrôle applicatif de cette
        // requête mais AVANT son insertion : seul l'index unique peut encore trancher. (Simulé sur la
        // même connexion, donc dans la transaction de la requête : annulé avec elle ici, alors qu'en
        // réalité l'autre utilisateur l'a déjà validé sur sa propre connexion.)
        $concurrentInsere = false;
        EncaissementVente::creating(function () use (&$concurrentInsere, $facture) {
            if ($concurrentInsere) {
                return;
            }
            $concurrentInsere = true;
            DB::table('encaissements_ventes')->insert([
                'id' => (string) Str::ulid(),
                'facture_vente_id' => $facture->id,
                'montant' => 500,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'mobile_money',
                'reference_paiement' => 'OM-SIMULTANE',
                'cle_reference_mobile_money' => $facture->organization_id.'|OM-SIMULTANE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->encaisserMobileMoney($user, $facture, $orange, 'OM-SIMULTANE')
            ->assertSessionHasErrors(['reference_paiement' => self::MESSAGE]);

        $this->assertTrue($concurrentInsere);
        $this->assertSame(0, EncaissementVente::where('montant', 1000)->count(), 'La saisie refusée n\'est pas enregistrée.');
        $this->assertSame(0, PieceComptable::where('source_type', (new EncaissementVente)->getMorphClass())->count(), 'Transaction annulée : aucune pièce pour la saisie refusée.');
    }

    // ── Migration : doublons historiques conservés ────────────────────────────

    public function test_la_migration_conserve_les_doublons_historiques_et_bloque_leur_reutilisation(): void
    {
        ['facture' => $facture, 'user' => $user, 'orange' => $orange] = $this->contexte();
        $migration = $this->migration();
        $migration->down();

        $inserer = function (string $reference, string $date, string $mode = 'mobile_money') use ($facture): string {
            $id = (string) Str::ulid();
            DB::table('encaissements_ventes')->insert([
                'id' => $id,
                'facture_vente_id' => $facture->id,
                'montant' => 1000,
                'date_encaissement' => substr($date, 0, 10),
                'mode_paiement' => $mode,
                'reference_paiement' => $reference,
                'created_at' => $date,
                'updated_at' => $date,
            ]);

            return $id;
        };
        $plusAncien = $inserer('om-dbl', '2026-09-20 08:00:00');
        $doublon = $inserer(' OM-DBL ', '2026-09-21 08:00:00');
        $unique = $inserer('OM-SEUL', '2026-09-21 09:00:00');
        $virement = $inserer('OM-DBL', '2026-09-21 10:00:00', 'virement');

        $migration->up();
        $migration->up();

        $cles = DB::table('encaissements_ventes')->pluck('cle_reference_mobile_money', 'id');
        $this->assertSame($facture->organization_id.'|OM-DBL', $cles[$plusAncien]);
        $this->assertNull($cles[$doublon], 'Doublon historique conservé sans clé.');
        $this->assertSame($facture->organization_id.'|OM-SEUL', $cles[$unique]);
        $this->assertNull($cles[$virement]);
        $this->assertSame(' OM-DBL ', DB::table('encaissements_ventes')->where('id', $doublon)->value('reference_paiement'), 'Référence historique jamais modifiée.');
        $this->assertTrue(Schema::hasIndex('encaissements_ventes', 'encaissements_ventes_cle_reference_mobile_money_unique'));

        $this->encaisserMobileMoney($user, $facture, $orange, 'OM-DBL')
            ->assertSessionHasErrors($this->dejaUtiliseePar($facture));

        EncaissementVente::findOrFail($doublon)->update(['note' => 'Vérifié']);
        $this->assertNull(DB::table('encaissements_ventes')->where('id', $doublon)->value('cle_reference_mobile_money'));
    }

    public function test_la_commande_de_diagnostic_liste_les_doublons_historiques(): void
    {
        ['facture' => $facture] = $this->contexte();
        foreach (['OM-HIST', 'om-hist'] as $i => $reference) {
            DB::table('encaissements_ventes')->insert([
                'id' => (string) Str::ulid(),
                'facture_vente_id' => $facture->id,
                'montant' => 1000,
                'date_encaissement' => '2026-09-2'.$i,
                'mode_paiement' => 'mobile_money',
                'reference_paiement' => $reference,
                'cle_reference_mobile_money' => $i === 0 ? $facture->organization_id.'|OM-HIST' : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->artisan('encaissements:doublons-reference-mobile-money')
            ->expectsOutputToContain('1 référence(s) Mobile Money utilisée(s) plusieurs fois')
            ->assertSuccessful();
    }
}
