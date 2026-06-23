<?php

namespace Database\Seeders;

use App\Models\Livreur;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class LivreursSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $livreurs = [
            // ── Membres équipes externes ────────────────────────────────────
            ['nom' => 'CAMARA',   'prenom' => 'Ibrahima',  'telephone' => '+224622000001'], // Nen Dow principal
            ['nom' => 'KOUYATÉ', 'prenom' => 'Sékou',     'telephone' => '+224622000002'], // Nen Dow assistant
            ['nom' => 'BAH',      'prenom' => 'Mariama',   'telephone' => '+224622000003'], // Auto Dogomet principal
            ['nom' => 'SOUMAH',   'prenom' => 'Mamadou',   'telephone' => '+224622000004'], // Kaloum Express principal
            ['nom' => 'KOUROUMA', 'prenom' => 'Fatoumata', 'telephone' => '+224622000005'], // Kaloum Express assistant
            ['nom' => 'DIALLO',   'prenom' => 'Boubacar',  'telephone' => '+224622000006'], // Conakry 2 principal
            ['nom' => 'BARRY',    'prenom' => 'Alpha',     'telephone' => '+224622000007'], // ELM Logistique 4 principal
            ['nom' => 'CAMARA',   'prenom' => 'Oumar',     'telephone' => '+224622000008'], // Baba Ousou principal
            ['nom' => 'SYLLA',    'prenom' => 'Abdoulaye', 'telephone' => '+224622000009'], // Baba Ousou assistant
            ['nom' => 'TOURÉ',   'prenom' => 'Kadiatou',  'telephone' => '+224622000010'], // Baba Ousou assistant

            // ── Membres équipes internes (véhicules elm-1 / elm-2 / elm-3) ──
            ['nom' => 'KONATÉ',  'prenom' => 'Boubacar',  'telephone' => '+224622000011'], // elm-1 principal (100 %)
            ['nom' => 'BALDÉ',   'prenom' => 'Aissatou',  'telephone' => '+224622000012'], // elm-2 principal (70 %)
            ['nom' => 'SALL',     'prenom' => 'Thierno',   'telephone' => '+224622000013'], // elm-2 assistant (30 %)
            ['nom' => 'KEÏTA',   'prenom' => 'Mamadou',   'telephone' => '+224622000014'], // elm-3 principal (50 %)
            ['nom' => 'TRAORÉ',  'prenom' => 'Djénabou',  'telephone' => '+224622000015'], // elm-3 assistant (30 %)
            ['nom' => 'FOFANA',   'prenom' => 'Lamine',    'telephone' => '+224622000016'], // elm-3 assistant (20 %)

            // ── Membres équipe interne Cousin (véhicule Cousin / site Kouria) ──
            ['nom' => 'TRAORE',   'prenom' => 'Mohamed',   'telephone' => '+224621346981'], // Cousin chauffeur 1 (25 %)
            ['nom' => 'DIAKITÉ',  'prenom' => 'Mamadi',    'telephone' => '+224624099568'], // Cousin chauffeur 2 (25 %)
            ['nom' => 'CISSE',    'prenom' => 'Amara',     'telephone' => '+224622458645'], // Cousin convoyeur 1 (15 %)
            ['nom' => 'DIABY',    'prenom' => 'Fode',      'telephone' => '+224623479658'], // Cousin convoyeur 2 (12,5 %)
            ['nom' => 'LENO',     'prenom' => 'Tamba',     'telephone' => '+224625145898'], // Cousin convoyeur 3 (12,5 %)
            ['nom' => 'CAMARA',   'prenom' => 'Kemo',      'telephone' => '+224623146589'], // Cousin convoyeur 4 (10 %)
        ];

        foreach ($livreurs as $data) {
            Livreur::firstOrCreate(
                ['telephone' => $data['telephone'], 'organization_id' => $org->id],
                [...$data, 'organization_id' => $org->id, 'is_active' => true]
            );
        }
    }
}
