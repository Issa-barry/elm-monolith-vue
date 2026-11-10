<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Contacts\EcartsIdentiteClients;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Écarts d'identité client / personne (ADR 0060, lot 7a, D16, L7-1). Simulation par défaut : liste
 * les champs à compléter et les conflits. --apply complète les champs vides et applique les
 * arbitrages explicites (--personne-fait-foi / --client-fait-foi, client par client) ; chaque
 * exécution est annulable (--annuler). --activer démarre la synchronisation R1 de l'organisation,
 * refusée tant qu'un conflit ou une complétion reste.
 */
class ContactsEcartsClientsCommand extends Command
{
    protected $signature = 'contacts:ecarts-clients
        {--acteur= : Id de l\'utilisateur au nom duquel la commande est faite (organisation, journal)}
        {--dry-run : Simulation, rien n\'est écrit (par défaut)}
        {--apply : Applique les complétions et les arbitrages (ou l\'annulation)}
        {--personne-fait-foi=* : Client dont les conflits sont tranchés en faveur de sa Personne}
        {--client-fait-foi=* : Client dont les conflits sont tranchés en faveur du client (recopié sur la Personne)}
        {--annuler= : Identifiant d\'une exécution appliquée à annuler}
        {--activer : Active la synchronisation de l\'identité client pour l\'organisation}';

    protected $description = 'Rapport et arbitrage des écarts d\'identité entre clients et fiches Personne (simulation par défaut).';

    public function handle(EcartsIdentiteClients $ecarts): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('--apply et --dry-run sont incompatibles.');

            return self::INVALID;
        }

        $acteur = User::find((string) $this->option('acteur'));
        if ($acteur === null || $acteur->organization_id === null) {
            $this->error('--acteur est obligatoire (id d\'un utilisateur rattaché à une organisation).');

            return self::INVALID;
        }

        $ecrit = $this->option('apply') || $this->option('activer');
        if ($ecrit && ! ($acteur->can('clients.update') && $acteur->can('contacts.update'))) {
            $this->error('L\'acteur doit avoir les droits clients.update et contacts.update.');

            return self::INVALID;
        }

        try {
            if ($this->option('activer')) {
                $recopies = $ecarts->activer($acteur);
                $this->info("Synchronisation activée. {$recopies} client(s) aligné(s) sur leur fiche Personne.");

                return self::SUCCESS;
            }

            if ($this->option('annuler') !== null) {
                $resultat = $ecarts->annuler($acteur, (string) $this->option('annuler'), ! $this->option('apply'));
                $this->info(($this->option('apply') ? 'Annulation : ' : 'Simulation d\'annulation : ')."{$resultat['annulees']} champ(s) restauré(s), {$resultat['conflits']} en conflit (modifiés depuis, non touchés).");

                return self::SUCCESS;
            }

            return $this->rapport($ecarts, $acteur);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first());

            return self::INVALID;
        }
    }

    private function rapport(EcartsIdentiteClients $ecarts, User $acteur): int
    {
        $arbitrages = [
            ...array_fill_keys((array) $this->option('personne-fait-foi'), EcartsIdentiteClients::PERSONNE_FAIT_FOI),
            ...array_fill_keys((array) $this->option('client-fait-foi'), EcartsIdentiteClients::CLIENT_FAIT_FOI),
        ];
        $analyse = $ecarts->analyser((string) $acteur->organization_id, $arbitrages);

        $this->table(['Client', 'Nom', 'Champ', 'Valeur client', 'Valeur personne', 'Action'], array_map(fn (array $l) => [
            $l['client_id'], $l['nom'], $l['champ'], $l['valeur_client'], $l['valeur_personne'], $l['action'],
        ], array_values(array_filter($analyse['lignes'], fn (array $l) => $l['action'] !== 'identique'))));
        foreach ($analyse['anomalies'] as $a) {
            $this->warn("Anomalie {$a['motif']} : client {$a['client_id']} ({$a['nom']})");
        }
        $this->line("Conflits à arbitrer : {$analyse['conflits']}");

        if (! $this->option('apply')) {
            $this->info('Simulation : rien n\'a été écrit. Relancez avec --apply pour appliquer les complétions et les arbitrages.');

            return self::SUCCESS;
        }

        $resultat = $ecarts->appliquer($acteur, $analyse);
        foreach ($resultat['refusees'] as $r) {
            $this->warn("Refusé pour le client {$r['client_id']} : {$r['motif']}");
        }
        $this->info($resultat['reprise'] !== null
            ? "Exécution {$resultat['reprise']->id} : {$resultat['appliquees']} champ(s) écrit(s). Annulation : --annuler={$resultat['reprise']->id} --apply"
            : 'Aucune complétion ni aucun arbitrage à appliquer.');

        return self::SUCCESS;
    }
}
