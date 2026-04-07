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
            // ── Membres Équipe Nord ─────────────────────────────────────────
            ['nom' => 'CAMARA',   'prenom' => 'Ibrahima',  'telephone' => '+224622000001'],
            ['nom' => 'KOUYATÉ', 'prenom' => 'Sékou',     'telephone' => '+224622000002'],
            // ── Membre Équipe Sud ───────────────────────────────────────────
            ['nom' => 'BAH',      'prenom' => 'Mariama',   'telephone' => '+224622000003'],
            // ── Membres Équipe Est ──────────────────────────────────────────
            ['nom' => 'SOUMAH',   'prenom' => 'Mamadou',   'telephone' => '+224622000004'],
            ['nom' => 'KOUROUMA', 'prenom' => 'Fatoumata', 'telephone' => '+224622000005'],
            // ── Membres Équipe Ouest ────────────────────────────────────────
            ['nom' => 'DIALLO',   'prenom' => 'Boubacar',  'telephone' => '+224622000006'],
            ['nom' => 'BARRY',    'prenom' => 'Alpha',     'telephone' => '+224622000007'],
            // ── Membres Équipe Centre ───────────────────────────────────────
            ['nom' => 'CAMARA',   'prenom' => 'Oumar',     'telephone' => '+224622000008'],
            ['nom' => 'SYLLA',    'prenom' => 'Abdoulaye', 'telephone' => '+224622000009'],
            ['nom' => 'TOURÉ',   'prenom' => 'Kadiatou',  'telephone' => '+224622000010'],
        ];

        foreach ($livreurs as $data) {
            Livreur::firstOrCreate(
                ['telephone' => $data['telephone'], 'organization_id' => $org->id],
                [...$data, 'organization_id' => $org->id, 'is_active' => true]
            );
        }
    }
}
