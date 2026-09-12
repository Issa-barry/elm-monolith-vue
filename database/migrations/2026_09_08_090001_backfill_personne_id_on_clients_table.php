<?php

use App\Models\Client;
use App\Models\Personne;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Rattache chaque client existant à une Personne, en réutilisant celle d'un autre rôle
     * (Proprietaire/Fournisseur/...) ou d'un autre client de la même organisation partageant
     * déjà ce téléphone — jamais de doublon d'identité — via Personne::resoudreOuCreer(), la
     * même méthode utilisée par tous les autres rôles. Non destructif : ne touche à aucune
     * colonne existante de `clients`, ne fait que renseigner `personne_id`.
     */
    public function up(): void
    {
        Client::whereNull('personne_id')->orderBy('id')->chunkById(200, function ($clients) {
            foreach ($clients as $client) {
                $personne = Personne::resoudreOuCreer($client->organization_id, [
                    'nom_complet' => $client->nom_complet,
                    'telephone' => $client->telephone,
                    'email' => $client->email,
                    'pays' => $client->pays,
                    'code_pays' => $client->code_pays,
                    'code_phone_pays' => $client->code_phone_pays,
                    'ville' => $client->ville,
                    'adresse' => $client->adresse,
                ]);

                $client->update(['personne_id' => $personne->id]);
            }
        });
    }

    /**
     * Ne supprime jamais les Personne créées par up() : elles peuvent entre-temps avoir été
     * réutilisées par un autre rôle (ex: ce client est aussi devenu Parrain). Seul le
     * rattachement client -> personne est défait.
     */
    public function down(): void
    {
        Client::whereNotNull('personne_id')->update(['personne_id' => null]);
    }
};
