<?php

use App\Models\Organization;
use App\Models\RegleValidationRole;
use Illuminate\Database\Migrations\Migration;

/**
 * Migration de DONNÉES — « Peut réceptionner pour » (décision du 12/10/2026). Réceptionner un bon de
 * commande fournisseur exigeait d'être rattaché à l'agence de livraison, sans aucune exception : un
 * super administrateur rattaché à une seule agence ne pouvait rien réceptionner ailleurs. Le
 * périmètre de réception devient un réglage par rôle (Paramètres → Achats), en plus du rattachement.
 * Chaque organisation existante reçoit la règle de départ du super administrateur : « toutes les
 * agences », modifiable ensuite.
 *
 * Non destructive et idempotente : aucune colonne ajoutée, et une règle déjà configurée n'est pas
 * modifiée. Les autres rôles gardent le comportement d'origine (agences de rattachement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Organization::query()->select('id')->chunkById(200, function ($organizations) {
            foreach ($organizations as $org) {
                RegleValidationRole::provisionnerReceptionsParDefaut($org->id);
            }
        });
    }

    /** Pas de rollback de données : les règles ont pu être reconfigurées depuis. */
    public function down(): void
    {
        //
    }
};
