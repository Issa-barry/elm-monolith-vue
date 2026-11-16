<?php

namespace Tests\Feature\Clients;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Reprise des clients existants par la migration de l'ADR 0066 : seuls les clients certainement
 * saisis au back-office sont qualifiés ; une fiche créée à l'inscription — ou douteuse — reste non
 * qualifiée, même déjà utilisée dans des ventes. Aucune nature n'est modifiée.
 */
class RepriseQualificationClientsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_16_120000_add_qualification_commerciale_to_clients.php';

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
    }

    private function client(?string $nomComplet, ?User $user, string $creeLe): string
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $user?->id,
            'type' => 'externe',
            'cashback_eligible' => false,
            'cashback_montant_par_pack' => null,
        ]);
        DB::table('clients')->where('id', $client->id)->update(['nom_complet' => $nomComplet, 'created_at' => $creeLe]);

        return $client->id;
    }

    private function compte(string $creeLe): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        DB::table('users')->where('id', $user->id)->update(['created_at' => $creeLe]);

        return $user;
    }

    private function rejouerMigration(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('clients', 'qualifie_le'));
        $migration->up();
    }

    private function qualifie(string $clientId): bool
    {
        return DB::table('clients')->where('id', $clientId)->value('qualifie_le') !== null;
    }

    public function test_seuls_les_clients_certainement_saisis_au_back_office_sont_qualifies(): void
    {
        $sansCompte = $this->client('Client Comptoir', null, '2026-05-01 10:00:00');

        $compteAncien = $this->compte('2026-09-24 20:08:08');
        $saisiAvantLeCompte = $this->client('Client Back-office', $compteAncien, '2026-06-01 09:00:00');

        $compteInscription = $this->compte('2026-09-24 20:08:08');
        $creeALInscription = $this->client(null, $compteInscription, '2026-09-24 20:08:08');

        // Douteux : nom saisi mais fiche créée en même temps que le compte → jamais qualifié d'office.
        $compteDouteux = $this->compte('2026-11-13 16:59:25');
        $douteuxMemeInstant = $this->client('Mohamed BANGOURA', $compteDouteux, '2026-11-13 16:59:26');

        // Douteux : sans nom saisi (obligatoire au back-office), même créée bien avant le compte.
        $compteSansNom = $this->compte('2026-11-01 10:00:00');
        $sansNomAncien = $this->client(null, $compteSansNom, '2026-04-01 10:00:00');

        $this->rejouerMigration();

        $this->assertTrue($this->qualifie($sansCompte));
        $this->assertTrue($this->qualifie($saisiAvantLeCompte));
        $this->assertFalse($this->qualifie($creeALInscription));
        $this->assertFalse($this->qualifie($douteuxMemeInstant));
        $this->assertFalse($this->qualifie($sansNomAncien));

        // Reprise : date de création reprise, aucun auteur inventé, nature intacte.
        $this->assertSame('2026-05-01 10:00:00', (string) DB::table('clients')->where('id', $sansCompte)->value('qualifie_le'));
        $this->assertNull(DB::table('clients')->where('id', $sansCompte)->value('qualifie_par'));
        $this->assertSame(5, DB::table('clients')->where('type', 'externe')->count());
    }
}
