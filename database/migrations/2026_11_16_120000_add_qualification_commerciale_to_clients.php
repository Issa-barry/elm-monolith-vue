<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qualification commerciale d'un client (ADR 0066). Un client créé à l'inscription d'un livreur ou
 * d'un propriétaire (RegistrationService, CreateNewUser) n'est pas qualifié : il ne peut pas être
 * choisi dans une vente tant qu'un utilisateur habilité (`clients.qualifier`) n'a pas confirmé sa
 * nature. `type` n'est jamais modifié ici.
 *
 * Reprise des clients existants (aucune donnée supprimée, aucune nature modifiée) :
 *  - qualifié (qualifie_le = created_at, qualifie_par vide) : client sans compte, ou client créé
 *    au back-office avant l'ouverture de son compte (créé plus de 2 minutes avant le compte) avec un
 *    nom saisi (`nom_complet`, obligatoire au back-office) ;
 *  - non qualifié dans tous les autres cas — compte rattaché ET (nom saisi absent OU fiche créée
 *    dans les 2 minutes autour de l'ouverture du compte, ou après) : en cas de doute, jamais de
 *    qualification automatique, même si le client a déjà des commandes.
 * Calcul en PHP pour rester identique sous SQLite (tests) et MySQL (production).
 */
return new class extends Migration
{
    private const FENETRE_INSCRIPTION_SECONDES = 120;

    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('qualifie_le')->nullable()->after('type');
            $table->foreignUlid('qualifie_par')->nullable()->after('qualifie_le')
                ->constrained('users')->nullOnDelete();
        });

        DB::table('clients')
            ->leftJoin('users', 'users.id', '=', 'clients.user_id')
            ->select(['clients.id', 'clients.user_id', 'clients.nom_complet', 'clients.created_at', 'users.created_at as compte_cree_le'])
            ->orderBy('clients.id')
            ->chunk(500, function ($clients) {
                foreach ($clients as $client) {
                    if ($this->creeALInscription($client)) {
                        continue;
                    }

                    DB::table('clients')->where('id', $client->id)->update([
                        'qualifie_le' => $client->created_at ?? now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('qualifie_par');
            $table->dropColumn('qualifie_le');
        });
    }

    private function creeALInscription(object $client): bool
    {
        if ($client->user_id === null) {
            return false;
        }

        if (trim((string) $client->nom_complet) === '') {
            return true;
        }

        if ($client->created_at === null || $client->compte_cree_le === null) {
            return true;
        }

        $ecart = Carbon::parse($client->compte_cree_le)->getTimestamp() - Carbon::parse($client->created_at)->getTimestamp();

        // Fiche créée avant le compte, au-delà de la fenêtre : client saisi au back-office puis
        // rattaché à l'inscription (branche « client existant », jamais recréé).
        return $ecart <= self::FENETRE_INSCRIPTION_SECONDES;
    }
};
