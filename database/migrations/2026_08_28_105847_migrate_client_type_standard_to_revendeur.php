<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renomme la nature de client `standard` en `revendeur` (cf. ClientType) — un revendeur est
 * automatiquement éligible au cashback (règle métier confirmée le 28/08/2026), donc les clients
 * concernés reçoivent aussi `cashback_eligible = true` ici. Aucun effet rétroactif sur les
 * transactions déjà historiques : CashbackService ne consulte `cashback_eligible` qu'au moment
 * d'un nouvel encaissement (EncaissementVenteController), jamais pour recalculer une vente déjà
 * traitée — cf. audit du 28/08/2026.
 *
 * `externe` reste inchangé (aucune migration de donnée nécessaire pour lui). `distributeur` est
 * une valeur neuve : aucune ligne existante n'en a besoin, ajoutée uniquement côté enum PHP
 * (colonne `type` en varchar libre, sans contrainte CHECK — pas de migration de schéma requise
 * pour accepter une nouvelle valeur).
 *
 * Le défaut de colonne ('standard') est corrigé en SQL brut (pas ->change(), doctrine/dbal non
 * installé, même limite que les migrations précédentes de ce projet) — sans effet pratique sur
 * SQLite (tests) car ClientFactory fixe désormais `type` explicitement, jamais via ce défaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')
            ->where('type', 'standard')
            ->update(['type' => 'revendeur', 'cashback_eligible' => true]);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE clients MODIFY type VARCHAR(20) NOT NULL DEFAULT 'revendeur'");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE clients MODIFY type VARCHAR(20) NOT NULL DEFAULT 'standard'");
        }

        DB::table('clients')
            ->where('type', 'revendeur')
            ->update(['type' => 'standard']);
    }
};
