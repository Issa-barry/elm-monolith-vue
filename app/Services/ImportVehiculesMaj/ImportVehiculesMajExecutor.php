<?php

namespace App\Services\ImportVehiculesMaj;

use App\Models\ImportVehiculesMaj;
use App\Models\Vehicule;
use App\Models\VehiculeCapacite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Applique réellement les mises à jour d'une analyse déjà validée (voir
 * ImportVehiculesMajParser). Tout-ou-rien : si une seule ligne est en erreur, rien n'est
 * enregistré. Ne crée JAMAIS de véhicule — une ligne dont l'immatriculation n'existe pas déjà
 * dans l'organisation est une erreur bloquante à l'analyse, jamais atteinte ici.
 *
 * Whitelist explicite des champs appliqués (jamais de fill()/update() à partir de la ligne
 * Excel brute) : seuls `site_id`, `livraison_vente`, `livraison_logistique` et les capacités
 * (VehiculeCapacite, une par catégorie détectée) peuvent être écrits — cf.
 * ImportVehiculesMajParser::analyserLigne(), qui ne construit `mise_a_jour` qu'à partir de ces
 * clés. Toute autre donnée du véhicule (nom, marque, modèle, type, catégorie, propriétaire...)
 * n'est jamais lue ni écrite par ce chemin.
 */
class ImportVehiculesMajExecutor
{
    public function __construct(private readonly ImportVehiculesMajParser $parser) {}

    /** @return array{succes: bool, rapport: array, compteurs: array{mis_a_jour: int}|null} */
    public function executer(ImportVehiculesMaj $import): array
    {
        // Ré-analyse à l'instant T (et non réutilisation du rapport d'aperçu) pour éviter tout
        // écart avec un changement survenu entre l'aperçu et la confirmation — même principe
        // qu'ImportFlotteExecutor/ImportProduitsExecutor.
        $absolutePath = Storage::disk('local')->path($import->fichier_path);
        $analyse = $this->parser->analyserFichier($absolutePath, $import->organization_id);

        $lignesErreur = array_filter($analyse['lignes'], fn ($l) => $l['statut'] === 'erreur');
        if (! empty($lignesErreur)) {
            return ['succes' => false, 'rapport' => $analyse, 'compteurs' => null];
        }

        $compteurs = ['mis_a_jour' => 0];

        DB::transaction(function () use ($analyse, $import, &$compteurs) {
            foreach ($analyse['lignes'] as $ligne) {
                if ($ligne['statut'] !== 'mise_a_jour') {
                    continue;
                }

                $this->appliquerLigne($ligne, $import->organization_id);
                $compteurs['mis_a_jour']++;
            }
        });

        return ['succes' => true, 'rapport' => $analyse, 'compteurs' => $compteurs];
    }

    private function appliquerLigne(array $ligne, string $orgId): void
    {
        $maj = $ligne['mise_a_jour'];
        $vehiculeId = $ligne['vehicule_id'];

        // Whitelist explicite : seules ces deux clés scalaires peuvent atterrir dans
        // l'UPDATE du véhicule — jamais un tableau construit à partir de la ligne Excel brute.
        $donneesVehicule = array_intersect_key($maj, array_flip(['site_id', 'livraison_vente', 'livraison_logistique']));
        if (! empty($donneesVehicule)) {
            Vehicule::whereKey($vehiculeId)->update($donneesVehicule);
        }

        foreach ($maj['capacites'] ?? [] as $capacite) {
            VehiculeCapacite::updateOrCreate(
                ['vehicule_id' => $vehiculeId, 'categorie_id' => $capacite['categorie_id']],
                ['organization_id' => $orgId, 'capacite_max' => $capacite['valeur']]
            );
        }
    }
}
