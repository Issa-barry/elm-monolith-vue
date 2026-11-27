<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index de la liste des dépenses et de Stat-Dépenses (docs/depenses-filtres.md, « Performances »).
 *
 * - C : l'index (organization_id, statut, date_depense) devient couvrant pour les sommes — mêmes trois
 *   premières colonnes, donc chaque requête qui l'utilisait peut toujours l'utiliser, et même nom,
 *   donc les FORCE INDEX du code restent valides avant comme après la migration.
 * - D3 : (organization_id, date_depense, created_at), l'ordre de la liste. InnoDB ajoute la clé
 *   primaire (id) à chaque index secondaire : le tri complet date, saisie, identifiant est lu dans
 *   l'index, sans tri.
 *
 * C n'est jamais déployé sans D3 : seul, il fait choisir à MySQL et MariaDB un mauvais index pour la
 * page de la liste. Mesures du 24/11/2026 (400 000 dépenses, MySQL 8.4 et MariaDB 11.8.9) : page de la
 * liste de 0,04–2 s à 1–40 ms, Stat-Dépenses divisé par 2 à 5 sur les longues périodes, +47 Mo d'index,
 * moins d'1 ms de plus par écriture.
 *
 * MySQL et MariaDB : une seule instruction, en ligne (ALGORITHM=INPLACE, LOCK=NONE) — l'index n'est
 * jamais absent, lectures et écritures restent permises (8 s pour 400 000 lignes sous MariaDB 11.8.9,
 * sans blocage). SQLite (tests) : mêmes index par le constructeur de schéma.
 *
 * Aucune donnée n'est modifiée ; down() rétablit exactement les index d'origine.
 */
return new class extends Migration
{
    private const INDEX_STATUT = 'depenses_organization_id_statut_date_depense_index';

    private const INDEX_DATE = 'dep_org_date_creation_idx';

    private const COLONNES_STATUT_ORIGINE = ['organization_id', 'statut', 'date_depense'];

    private const COLONNES_STATUT_COUVRANT = ['organization_id', 'statut', 'date_depense', 'deleted_at', 'site_id', 'user_id', 'depense_type_id', 'montant'];

    private const COLONNES_DATE = ['organization_id', 'date_depense', 'created_at'];

    public function up(): void
    {
        if ($this->moteurEnLigne()) {
            DB::statement(sprintf(
                'ALTER TABLE depenses DROP INDEX %1$s, ADD INDEX %1$s (%2$s), ADD INDEX %3$s (%4$s), ALGORITHM=INPLACE, LOCK=NONE',
                self::INDEX_STATUT, implode(', ', self::COLONNES_STATUT_COUVRANT),
                self::INDEX_DATE, implode(', ', self::COLONNES_DATE),
            ));

            return;
        }

        Schema::table('depenses', function (Blueprint $table) {
            $table->dropIndex(self::INDEX_STATUT);
            $table->index(self::COLONNES_STATUT_COUVRANT, self::INDEX_STATUT);
            $table->index(self::COLONNES_DATE, self::INDEX_DATE);
        });
    }

    public function down(): void
    {
        if ($this->moteurEnLigne()) {
            DB::statement(sprintf(
                'ALTER TABLE depenses DROP INDEX %2$s, DROP INDEX %1$s, ADD INDEX %1$s (%3$s), ALGORITHM=INPLACE, LOCK=NONE',
                self::INDEX_STATUT, self::INDEX_DATE, implode(', ', self::COLONNES_STATUT_ORIGINE),
            ));

            return;
        }

        Schema::table('depenses', function (Blueprint $table) {
            $table->dropIndex(self::INDEX_DATE);
            $table->dropIndex(self::INDEX_STATUT);
            $table->index(self::COLONNES_STATUT_ORIGINE, self::INDEX_STATUT);
        });
    }

    private function moteurEnLigne(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
