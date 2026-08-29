<?php

namespace Database\Seeders\Organizations\FelloDemo;

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Clients de démonstration (modèle Client, pas User+rôle client — c'est ce
 * modèle que référence CommandeVente.client_id / PdvCheckoutRequest, cf.
 * pattern "client sans compte" de ClientsInscriptionSeeder). Numéros
 * réservés à la démo (+224690000...), sans collision avec les seeders "elm".
 */
class FelloDemoCustomersSeeder extends Seeder
{
    private const CLIENTS = [
        ['nom' => 'Comptoir', 'prenom' => 'Client', 'telephone' => '+224690000001', 'cashback' => false],
        ['nom' => 'DIALLO', 'prenom' => 'Mariama', 'telephone' => '+224690000002', 'cashback' => true],
        ['nom' => 'CAMARA', 'prenom' => 'Alpha', 'telephone' => '+224690000003', 'cashback' => true],
        ['nom' => 'BAH', 'prenom' => 'Fatoumata', 'telephone' => '+224690000004', 'cashback' => true],
        ['nom' => 'Société Kamsar Mode', 'prenom' => null, 'telephone' => '+224690000005', 'cashback' => false],
    ];

    public function run(): void
    {
        $org = Organization::where('slug', 'fello-demo')->firstOrFail();

        foreach (self::CLIENTS as $data) {
            Client::updateOrCreate(
                ['telephone' => $data['telephone'], 'organization_id' => $org->id],
                [
                    'nom' => $data['nom'],
                    'prenom' => $data['prenom'],
                    'code_phone_pays' => '+224',
                    'code_pays' => 'GN',
                    'pays' => 'Guinée',
                    'ville' => 'Conakry',
                    'is_active' => true,
                    // Explicite plutôt que de reposer sur le défaut colonne (cf. ClientFactory,
                    // même remarque) : un client de démo "Externe" reste compatible avec
                    // cashback_eligible=true ou false, sans déclencher la règle "montant par
                    // pack obligatoire" réservée aux Revendeurs (cf. CashbackEligibiliteService).
                    'type' => ClientType::EXTERNE->value,
                    'cashback_eligible' => $data['cashback'],
                    'user_id' => null,
                ]
            );
        }

        $nb = count(self::CLIENTS);
        $this->command->info("✓ {$nb} clients de démonstration créés.");
    }
}
