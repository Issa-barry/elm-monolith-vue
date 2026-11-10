<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Contacts\RepriseProprietairesEntreprises;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Reprise des propriétaires de type entreprise déjà enregistrés vers des fiches Entreprise
 * (ADR 0060, lot 5). Simulation par défaut ; --apply écrit et affiche l'identifiant d'exécution ;
 * --annuler=<identifiant> retire les rattachements de cette exécution (simulation sans --apply).
 * Une correspondance par raison sociale n'est jamais reliée d'office : elle se tranche avec
 * --relier=<propriétaire>:<entreprise> ou --creer=<propriétaire>. Aucune entreprise ni aucun
 * interlocuteur n'est jamais supprimé.
 */
class ContactsReprendreProprietairesEntreprisesCommand extends Command
{
    protected $signature = 'contacts:reprendre-proprietaires-entreprises
        {--acteur= : Id de l\'utilisateur au nom duquel la reprise est faite (organisation, journal)}
        {--dry-run : Simulation, rien n\'est écrit (par défaut)}
        {--apply : Applique les actions (ou l\'annulation)}
        {--relier=* : Arbitrage « <propriétaire>:<entreprise> » : relier ce propriétaire à cette entreprise}
        {--creer=* : Arbitrage « <propriétaire> » : créer une nouvelle entreprise pour ce propriétaire}
        {--annuler= : Identifiant d\'une exécution appliquée à annuler}';

    protected $description = 'Relie les propriétaires de type entreprise à des fiches Entreprise (simulation par défaut, annulable).';

    public function handle(RepriseProprietairesEntreprises $reprise): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('--apply et --dry-run sont incompatibles.');

            return self::INVALID;
        }

        $acteur = $this->acteur();
        if ($acteur === null) {
            return self::INVALID;
        }

        $simulation = ! $this->option('apply');
        if (! $simulation && ! ($acteur->can('proprietaires.update') && $acteur->can('contacts.update'))) {
            $this->error('L\'acteur doit avoir les droits proprietaires.update et contacts.update pour appliquer.');

            return self::INVALID;
        }

        try {
            return $this->option('annuler') !== null
                ? $this->annuler($reprise, $acteur, (string) $this->option('annuler'), $simulation)
                : $this->reprendre($reprise, $acteur, $simulation);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }
    }

    private function reprendre(RepriseProprietairesEntreprises $reprise, User $acteur, bool $simulation): int
    {
        $plan = $reprise->planifier((string) $acteur->organization_id, $this->arbitragesRelier(), array_values((array) $this->option('creer')));

        if ($simulation) {
            $this->tableau($plan);
            $this->info('Simulation : rien n\'a été écrit. Relancez avec --apply pour appliquer.');

            return self::SUCCESS;
        }

        $resultat = $reprise->appliquer($acteur, $plan);
        $this->tableau($resultat['resultats']);
        $this->info($resultat['reprise'] !== null
            ? "Exécution {$resultat['reprise']->id} appliquée. Annulation : --annuler={$resultat['reprise']->id} --apply"
            : 'Aucune action à appliquer.');

        return self::SUCCESS;
    }

    private function annuler(RepriseProprietairesEntreprises $reprise, User $acteur, string $id, bool $simulation): int
    {
        $resultat = $reprise->annuler($acteur, $id, $simulation);

        $this->table(['Ligne', 'Propriétaire', 'Action', 'Motif'], array_map(fn (array $r) => [
            $r['ligne_id'], $r['proprietaire_id'], $r['action'], $r['motif'] ?? '',
        ], $resultat['resultats']));

        if ($simulation) {
            $this->info('Simulation : rien n\'a été écrit. Relancez avec --apply pour annuler.');
        } elseif ($resultat['annulation'] !== null) {
            $this->info("Annulation enregistrée : {$resultat['annulation']->id}. Entreprises et interlocuteurs conservés.");
        } else {
            $this->info('Rien à annuler : toutes les lignes de cette exécution sont déjà annulées.');
        }

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $lignes */
    private function tableau(array $lignes): void
    {
        $this->table(['Propriétaire', 'Raison sociale', 'Action', 'Entreprise', 'Détail'], array_map(fn (array $l) => [
            $l['proprietaire_id'],
            $l['raison_sociale'] ?? '',
            $l['action'],
            $l['entreprise_id'] ?? '',
            $l['motif'] ?? $this->correspondances($l['correspondances'] ?? []),
        ], $lignes));
    }

    /** @param list<array{id: string, raison_sociale: string, deja_proprietaire: bool}> $correspondances */
    private function correspondances(array $correspondances): string
    {
        if ($correspondances === []) {
            return '';
        }

        return 'à arbitrer : '.implode(', ', array_map(
            fn (array $c) => $c['id'].' '.$c['raison_sociale'].($c['deja_proprietaire'] ? ' (déjà propriétaire)' : ''),
            $correspondances,
        ));
    }

    /** @return array<string, string> */
    private function arbitragesRelier(): array
    {
        $relier = [];
        foreach ((array) $this->option('relier') as $valeur) {
            [$proprietaire, $entreprise] = array_pad(explode(':', (string) $valeur, 2), 2, '');
            if ($proprietaire === '' || $entreprise === '') {
                throw new InvalidArgumentException("--relier attend « <propriétaire>:<entreprise> », reçu « {$valeur} ».");
            }
            $relier[$proprietaire] = $entreprise;
        }

        return $relier;
    }

    private function acteur(): ?User
    {
        $valeur = trim((string) $this->option('acteur'));
        if ($valeur === '') {
            $this->error('--acteur est obligatoire (id de l\'utilisateur).');

            return null;
        }

        $acteur = User::find($valeur);
        if ($acteur === null || $acteur->organization_id === null) {
            $this->error('Acteur introuvable ou sans organisation.');

            return null;
        }

        return $acteur;
    }
}
