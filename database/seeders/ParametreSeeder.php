<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Parametre;
use Illuminate\Database\Seeder;

class ParametreSeeder extends Seeder
{
    private const DEFAULTS = [
        [
            'cle' => Parametre::CLE_SEUIL_STOCK_FAIBLE,
            'valeur' => '10',
            'type' => Parametre::TYPE_INTEGER,
            'groupe' => Parametre::GROUPE_GENERAL,
            'description' => 'Seuil à partir duquel un stock est considéré comme faible',
        ],
        [
            'cle' => Parametre::CLE_NOTIFICATIONS_STOCK_ACTIVES,
            'valeur' => '1',
            'type' => Parametre::TYPE_BOOLEAN,
            'groupe' => Parametre::GROUPE_GENERAL,
            'description' => 'Activer les alertes de stock faible',
        ],
        [
            'cle' => Parametre::CLE_PRIX_ROULEAU_DEFAUT,
            'valeur' => '500',
            'type' => Parametre::TYPE_INTEGER,
            'groupe' => Parametre::GROUPE_PACKING,
            'description' => 'Prix par rouleau appliqué par défaut lors de la création d\'un packing (GNF)',
        ],
        [
            'cle' => Parametre::CLE_PRODUIT_ROULEAU_ID,
            'valeur' => null,
            'type' => Parametre::TYPE_INTEGER,
            'groupe' => Parametre::GROUPE_PACKING,
            'description' => 'Identifiant du produit utilisé comme rouleau dans les packings',
        ],
        [
            'cle' => Parametre::CLE_TAUX_PROPRIETAIRE_DEFAUT,
            'valeur' => '100',
            'type' => Parametre::TYPE_DECIMAL,
            'groupe' => Parametre::GROUPE_VEHICULES,
            'description' => 'Taux de commission attribué au propriétaire par défaut (%) lors de la création d\'un véhicule sans équipe',
        ],
    ];

    public function run(): void
    {
        foreach (Organization::all() as $org) {
            foreach (self::DEFAULTS as $param) {
                Parametre::firstOrCreate(
                    ['organization_id' => $org->id, 'cle' => $param['cle']],
                    array_merge(['organization_id' => $org->id], $param),
                );
            }
        }
    }
}
