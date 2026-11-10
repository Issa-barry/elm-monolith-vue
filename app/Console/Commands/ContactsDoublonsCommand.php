<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Contacts\DetectionDoublons;
use Illuminate\Console\Command;

/**
 * Doublons probables d'entreprises du référentiel Contacts (ADR 0060, lot 9a). **Lecture seule** :
 * aucune fusion, aucun rattachement, aucune écriture en base. Les personnes ne sont jamais
 * rapprochées : deux numéros différents désignent deux personnes (CONTACT-053). Rapport à l'écran et,
 * avec --export, fichier CSV (une ligne par paire).
 */
class ContactsDoublonsCommand extends Command
{
    protected $signature = 'contacts:doublons
        {--acteur= : Id de l\'utilisateur au nom duquel la commande est lancée (organisation analysée)}
        {--export= : Chemin d\'un fichier CSV à écrire (une ligne par paire)}';

    protected $description = 'Doublons probables d\'entreprises, avec ce qui bloquerait leur fusion (lecture seule).';

    public function handle(DetectionDoublons $detection): int
    {
        $acteur = User::find((string) $this->option('acteur'));
        if ($acteur === null || $acteur->organization_id === null) {
            $this->error('--acteur est obligatoire (id d\'un utilisateur rattaché à une organisation).');

            return self::INVALID;
        }
        if (! $acteur->can('contacts.read')) {
            $this->error('L\'acteur doit avoir le droit contacts.read.');

            return self::INVALID;
        }

        $analyse = $detection->analyser((string) $acteur->organization_id);
        $resume = $analyse['resume'];
        $paires = $analyse['entreprises'];

        $this->info('Doublons probables d\'entreprises (lecture seule, rien n\'est modifié)');
        $this->line('Personnes : jamais rapprochées, deux numéros différents désignent deux personnes.');
        $this->table(['Mesure', 'Nombre'], [
            ['Paires d\'entreprises', $resume['paires_entreprises']],
            ['Fusionnables sans blocage', $resume['fusionnables']],
            ['Bloquées : même fonction des deux côtés (F1)', $resume['bloquees_meme_fonction']],
        ]);

        if ($paires !== []) {
            $this->table(['Entreprise A', 'Entreprise B', 'Critères', 'Fonctions A', 'Fonctions B', 'Blocages'],
                array_map(fn (array $p) => [
                    $this->libelleFiche($p['a']),
                    $this->libelleFiche($p['b']),
                    $this->criteres($p),
                    $this->fonctions($p['a']),
                    $this->fonctions($p['b']),
                    $this->blocages($p),
                ], $paires));
        }

        if ($this->option('export') !== null) {
            $chemin = (string) $this->option('export');
            $this->exporter($chemin, $paires);
            $this->info("Export écrit : {$chemin}");
        }

        return self::SUCCESS;
    }

    private function libelleFiche(array $fiche): string
    {
        return trim("{$fiche['nom']} · ".($fiche['identifiant_fiscal'] ?? 'sans NIF')." · {$fiche['id']}");
    }

    private function criteres(array $paire): string
    {
        return implode(', ', array_map(fn (string $c) => DetectionDoublons::CRITERES[$c] ?? $c, $paire['criteres']));
    }

    private function fonctions(array $fiche): string
    {
        $liste = array_map(fn (array $f) => $f['fonction'] === 'interlocuteurs'
            ? "{$f['id']} interlocuteur(s)"
            : $f['fonction'].($f['reference'] ? " {$f['reference']}" : '').($f['actif'] === false ? ' (inactif)' : ''), $fiche['fonctions']);

        return implode(', ', $liste ?: ['aucune']);
    }

    private function blocages(array $paire): string
    {
        return implode(' | ', array_column($paire['blocages'], 'detail') ?: ['aucun']);
    }

    /** @param  list<array<string, mixed>>  $paires */
    private function exporter(string $chemin, array $paires): void
    {
        $flux = fopen($chemin, 'w');
        fwrite($flux, "\xEF\xBB\xBF");
        fputcsv($flux, ['criteres', 'a_id', 'a_raison_sociale', 'a_nif', 'a_telephone', 'a_email', 'a_ville', 'a_fonctions', 'b_id', 'b_raison_sociale', 'b_nif', 'b_telephone', 'b_email', 'b_ville', 'b_fonctions', 'blocages'], ';');
        foreach ($paires as $p) {
            $fiche = fn (array $f) => [$f['id'], $f['nom'], $f['identifiant_fiscal'], $f['telephone'], $f['email'], $f['ville'], $this->fonctions($f)];
            fputcsv($flux, [
                $this->criteres($p),
                ...$fiche($p['a']),
                ...$fiche($p['b']),
                implode(' | ', array_column($p['blocages'], 'detail')),
            ], ';');
        }
        fclose($flux);
    }
}
