<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Couvre la migration migrate_client_type_standard_to_revendeur : les migrations tournent déjà
 * (RefreshDatabase) sur une base vide, donc aucune ligne 'standard' n'existe au moment où elles
 * s'exécutent — ce test simule les données de production déjà en place en ré-exécutant up() sur
 * une ligne insérée après coup, exactement le scénario réel au déploiement.
 */
class MigrateClientTypeStandardToRevendeurTest extends TestCase
{
    use RefreshDatabase;

    public function test_up_convertit_standard_en_revendeur_et_force_le_cashback(): void
    {
        $org = Organization::factory()->create();

        // Insertion SQL brute : le cast ClientType du modèle Eloquent refuserait 'standard'.
        DB::table('clients')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $org->id,
            'nom_complet' => 'Ancien client standard',
            'telephone' => '+224622000099',
            'is_active' => true,
            'type' => 'standard',
            'cashback_eligible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (require database_path('migrations/2026_08_28_105847_migrate_client_type_standard_to_revendeur.php'))->up();

        $client = Client::where('nom_complet', 'Ancien client standard')->firstOrFail();
        $this->assertSame('revendeur', $client->type->value);
        $this->assertTrue($client->cashback_eligible);
    }

    public function test_up_ne_touche_pas_aux_clients_externes(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->create([
            'organization_id' => $org->id,
            'type' => 'externe',
            'cashback_eligible' => false,
        ]);

        (require database_path('migrations/2026_08_28_105847_migrate_client_type_standard_to_revendeur.php'))->up();

        $client->refresh();
        $this->assertSame('externe', $client->type->value);
        $this->assertFalse($client->cashback_eligible);
    }
}
