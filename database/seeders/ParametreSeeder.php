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
            'description' => 'Seuil a partir duquel un stock est considere comme faible',
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
            'description' => 'Prix par rouleau applique par defaut lors de la creation d un packing (GNF)',
        ],
        [
            'cle' => Parametre::CLE_PRODUIT_ROULEAU_ID,
            'valeur' => null,
            'type' => Parametre::TYPE_INTEGER,
            'groupe' => Parametre::GROUPE_PACKING,
            'description' => 'Identifiant du produit utilise comme rouleau dans les packings',
        ],
        [
            'cle' => Parametre::CLE_TAUX_PROPRIETAIRE_DEFAUT,
            'valeur' => '60',
            'type' => Parametre::TYPE_DECIMAL,
            'groupe' => Parametre::GROUPE_VEHICULES,
            'description' => 'Taux de commission attribue au proprietaire par defaut (%) lors de la creation d un vehicule sans equipe',
        ],
        [
            'cle' => Parametre::CLE_CASHBACK_SEUIL_ACHAT,
            'valeur' => '500000',
            'type' => Parametre::TYPE_INTEGER,
            'groupe' => Parametre::GROUPE_CASHBACK,
            'description' => 'Montant total d achats (GNF) qu un client doit atteindre pour declencher un cashback',
        ],
        [
            'cle' => Parametre::CLE_CASHBACK_MONTANT_GAIN,
            'valeur' => '25000',
            'type' => Parametre::TYPE_INTEGER,
            'groupe' => Parametre::GROUPE_CASHBACK,
            'description' => 'Montant du cashback verse en cash au client lors du franchissement du seuil (GNF)',
        ],
        [
            'cle' => Parametre::CLE_VENTES_COMMISSION_MODE,
            'valeur' => Parametre::COMMISSION_MODE_COMMANDE_VALIDEE,
            'type' => Parametre::TYPE_STRING,
            'groupe' => Parametre::GROUPE_VENTES,
            'description' => 'Moment de generation des commissions de vente',
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
