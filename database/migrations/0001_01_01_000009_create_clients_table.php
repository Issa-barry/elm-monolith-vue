<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            // Identité civile (nom/prenom) conservée pour compat mais jamais demandée dans
            // l'interface — comme Livreur, l'UI ne saisit que nom_complet (facultatif en base,
            // toujours renseigné en pratique par le formulaire).
            $table->string('nom', 100)->nullable();
            $table->string('prenom', 100)->nullable();
            $table->string('nom_complet', 150)->nullable();
            $table->string('email')->nullable();
            $table->string('telephone', 20)->nullable();
            $table->text('adresse')->nullable();
            $table->string('pays', 100)->nullable();
            $table->string('code_pays', 2)->nullable();
            $table->string('code_phone_pays', 10)->nullable();
            $table->string('ville', 100)->nullable();
            $table->boolean('is_active')->default(true);
            // Nature du client — valeurs et défaut réellement appliqués évoluent via des
            // migrations ultérieures (cf. migrate_client_type_standard_to_revendeur) ; se fier à
            // App\Enums\ClientType comme seule source de vérité, jamais à ce commentaire figé.
            $table->string('type', 20)->default('standard');
            $table->boolean('cashback_eligible')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'telephone']);
            $table->index(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
