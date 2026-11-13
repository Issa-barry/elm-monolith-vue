<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 9b (ADR 0060) : fusion manuelle de deux fiches Personne ou Entreprise.
 *
 * - Fiche absorbée : archivée (suppression logique) avec un lien vers la fiche conservée.
 * - Téléphone secondaire normalisé : reconnu par la recherche « téléphone d'abord », sans
 *   rattachement automatique (décision 3). Rempli ici depuis les valeurs existantes.
 * - Journal : une fusion, et chaque valeur écrite (avant / après) pour l'annulation sûre.
 *
 * Non destructive : colonnes et tables ajoutées, aucune donnée existante modifiée hormis la copie
 * normalisée du téléphone secondaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['personnes' => 'pers', 'entreprises_tierces' => 'ent_t'] as $table => $court) {
            Schema::table($table, function (Blueprint $t) use ($table, $court) {
                $t->string('telephone_secondaire_normalise', 30)->nullable()->after('telephone_secondaire');
                $t->foreignUlid('fusionnee_dans_id')->nullable()->after('organization_id')
                    ->constrained($table, 'id', "{$court}_fusionnee_dans_fk")->nullOnDelete();
                $t->timestamp('fusionnee_le')->nullable()->after('fusionnee_dans_id');
                $t->index(['organization_id', 'telephone_secondaire_normalise'], "{$court}_org_tel_secondaire_idx");
            });

            DB::table($table)->whereNotNull('telephone_secondaire')->where('telephone_secondaire', '!=', '')
                ->orderBy('id')->select(['id', 'telephone_secondaire'])
                ->chunk(500, function ($lignes) use ($table) {
                    foreach ($lignes as $ligne) {
                        $chiffres = preg_replace('/\D+/', '', (string) $ligne->telephone_secondaire) ?? '';
                        DB::table($table)->where('id', $ligne->id)->update(['telephone_secondaire_normalise' => $chiffres !== '' ? $chiffres : null]);
                    }
                });
        }

        Schema::create('contacts_fusions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('type', 20);
            $t->char('fiche_conservee_id', 26);
            $t->char('fiche_absorbee_id', 26);
            $t->string('statut', 30);
            // Jeton du formulaire : un double envoi ne fusionne qu'une fois.
            $t->char('jeton', 36)->unique();
            $t->foreignUlid('acteur_id')->nullable()->constrained('users')->nullOnDelete();
            $t->json('simulation');
            $t->json('choix');
            $t->json('confirmations');
            $t->timestamp('annulee_le')->nullable();
            $t->foreignUlid('annulee_par')->nullable()->constrained('users')->nullOnDelete();
            $t->json('annulation')->nullable();
            $t->timestamps();

            $t->index(['organization_id', 'created_at'], 'contacts_fusions_org_date_idx');
            $t->index(['type', 'fiche_absorbee_id'], 'contacts_fusions_absorbee_idx');
            $t->index(['type', 'fiche_conservee_id'], 'contacts_fusions_conservee_idx');
        });

        Schema::create('contacts_fusion_operations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('contacts_fusion_id')->constrained('contacts_fusions')->cascadeOnDelete();
            $t->unsignedSmallInteger('ordre');
            $t->string('nature', 20);
            $t->string('table_cible', 60);
            $t->char('cible_id', 26);
            $t->string('champ', 60);
            $t->text('valeur_avant')->nullable();
            $t->text('valeur_apres')->nullable();
            $t->string('libelle', 255);
            $t->string('annulation_statut', 20)->nullable();
            $t->timestamps();

            $t->index(['contacts_fusion_id', 'ordre'], 'contacts_fusion_ops_ordre_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts_fusion_operations');
        Schema::dropIfExists('contacts_fusions');

        foreach (['personnes' => 'pers', 'entreprises_tierces' => 'ent_t'] as $table => $court) {
            Schema::table($table, function (Blueprint $t) use ($court) {
                $t->dropForeign("{$court}_fusionnee_dans_fk");
                $t->dropIndex("{$court}_org_tel_secondaire_idx");
                $t->dropColumn(['telephone_secondaire_normalise', 'fusionnee_dans_id', 'fusionnee_le']);
            });
        }
    }
};
