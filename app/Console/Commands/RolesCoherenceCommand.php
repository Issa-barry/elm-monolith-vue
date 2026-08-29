<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

/**
 * Détecte les comptes dont le rattachement métier (Client/Proprietaire/
 * Livreur.user_id) n'est pas reflété par le rôle Spatie correspondant — les deux
 * DOIVENT toujours coïncider par construction (tous les flux d'inscription/
 * liaison posent user_id ET assignRole() dans la même transaction), un écart
 * signale soit un flux de liaison qui a oublié assignRole() (corrigé le
 * 26/08/2026 pour LoginController/BackofficeLoginController::
 * lierCompteParTelephone(), les deux seuls endroits qui avaient ce bug), soit une
 * intervention manuelle en base.
 *
 * Par défaut, purement informatif — même principe que
 * StockReservationCoherenceCommand : ne corrige rien tant que --fix n'est pas
 * explicitement passé, un rattachement métier a pu être fait par erreur (mauvais
 * numéro, doublon), attribuer le rôle automatiquement compenserait une erreur de
 * données par une autre plutôt que de la faire remonter à un humain. --fix ne
 * fait qu'attribuer le rôle Spatie déjà cohérent avec un `user_id` DÉJÀ posé (le
 * rattachement métier lui-même n'est jamais recréé/deviné ici) — c'est le geste
 * qu'un flux d'inscription aurait dû faire au moment de la liaison.
 */
class RolesCoherenceCommand extends Command
{
    protected $signature = 'roles:verifier-coherence-metier
        {--organization= : ID d\'organisation ; toutes si omis}
        {--fix : Attribue le rôle Spatie manquant pour chaque écart détecté (le rattachement user_id, lui, n\'est jamais modifié)}';

    protected $description = 'Détecte (et corrige avec --fix) les comptes liés à un profil Client/Proprietaire/Livreur sans le rôle Spatie correspondant.';

    /** @var array<string, class-string> */
    private const PROFILES = [
        'client' => Client::class,
        'proprietaire' => Proprietaire::class,
        'livreur' => Livreur::class,
    ];

    public function handle(): int
    {
        $orgId = $this->option('organization');
        $fix = (bool) $this->option('fix');
        $ecarts = [];

        foreach (self::PROFILES as $role => $modelClass) {
            $rows = $modelClass::query()
                ->whereNotNull('user_id')
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->with('user')
                ->get(['id', 'user_id', 'organization_id']);

            foreach ($rows as $row) {
                $user = $row->user;

                if ($user === null) {
                    // user_id pointe vers un User supprimé/inexistant — signalé mais hors
                    // périmètre de cette commande (pas un écart de rôle).
                    continue;
                }

                if (! $user->hasRole($role)) {
                    $ecarts[] = [
                        'role' => $role,
                        'user' => $user,
                        'profil' => class_basename($modelClass).' #'.$row->id,
                        'organization_id' => $row->organization_id,
                        'roles_avant' => $user->getRoleNames()->implode(', ') ?: '(aucun)',
                    ];
                }
            }
        }

        if (empty($ecarts)) {
            $this->info('Aucun écart détecté entre les profils métier liés et les rôles Spatie.');

            return self::SUCCESS;
        }

        if ($fix) {
            return $this->corriger($ecarts);
        }

        $this->afficher($ecarts, corrige: false);
        $this->newLine();
        $this->line('Relancez avec --fix pour attribuer les rôles manquants (le rattachement user_id n\'est jamais modifié).');

        return self::FAILURE;
    }

    /** @param  list<array{role: string, user: User, profil: string, organization_id: ?string, roles_avant: string}>  $ecarts */
    private function corriger(array $ecarts): int
    {
        foreach ($ecarts as $ecart) {
            Role::firstOrCreate(['name' => $ecart['role'], 'guard_name' => 'web']);
            $ecart['user']->assignRole($ecart['role']);

            Log::info('Rôle métier rattrapé par roles:verifier-coherence-metier --fix.', [
                'role' => $ecart['role'],
                'user_id' => $ecart['user']->id,
                'profil' => $ecart['profil'],
                'organization_id' => $ecart['organization_id'],
            ]);
        }

        $this->afficher($ecarts, corrige: true);
        $this->newLine();
        $this->info(count($ecarts).' rôle(s) attribué(s).');

        return self::SUCCESS;
    }

    /** @param  list<array{role: string, user: User, profil: string, organization_id: ?string, roles_avant: string}>  $ecarts */
    private function afficher(array $ecarts, bool $corrige): void
    {
        $verbe = $corrige ? 'corrigé(s)' : 'détecté(s)';
        $this->warn(count($ecarts)." écart(s) {$verbe} :");

        $this->table(
            ['Rôle', 'Profil lié', 'User ID', 'Organisation', 'Rôles avant correction'],
            array_map(fn (array $e) => [
                $e['role'],
                $e['profil'],
                $e['user']->id,
                $e['organization_id'],
                $e['roles_avant'],
            ], $ecarts),
        );
    }
}
