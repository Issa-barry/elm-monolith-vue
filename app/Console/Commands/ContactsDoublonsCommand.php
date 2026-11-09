<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Contacts\DetectionDoublons;
use Illuminate\Console\Command;

/**
 * Doublons probables du référentiel Contacts (ADR 0060, lot 9a). **Lecture seule** : aucune fusion,
 * aucun rattachement, aucune écriture en base. Rapport à l'écran et, avec --export, fichier CSV
 * (une ligne par paire) pour l'analyse avant de décider du périmètre de la fusion (lot 9b).
 */
class ContactsDoublonsCommand extends Command
{
    protected $signature = 'contacts:doublons
        {--acteur= : Id de l\'utilisateur au nom duquel la commande est lancée (organisation analysée)}
        {--export= : Chemin d\'un fichier CSV à écrire (une ligne par paire)}';

    protected $description = 'Doublons probables de personnes et d\'entreprises, avec ce qui bloquerait leur fusion (lecture seule).';

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

        $this->info('Doublons probables (lecture seule, rien n\'est modifié)');
        $this->table(['Mesure', 'Nombre'], [
            ['Paires de personnes', $resume['paires_personnes']],
            ['Paires d\'entreprises', $resume['paires_entreprises']],
            ['Fusionnables sans blocage', $resume['fusionnables']],
            ['Bloquées : même fonction des deux côtés (F1)', $resume['bloquees_meme_fonction']],
            ['Bloquées : un compte de chaque côté (F2)', $resume['bloquees_deux_comptes']],
            ['Rattachement de fonctions à un compte à confirmer (ADR 0056)', $resume['avec_rattachement']],
        ]);

        $paires = [...$analyse['personnes'], ...$analyse['entreprises']];
        if ($paires !== []) {
            $this->table(['Type', 'Fiche A', 'Fiche B', 'Critères', 'Fonctions A', 'Fonctions B', 'Blocages / rattachement'],
                array_map(fn (array $p) => [
                    $p['type'],
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
        return trim("{$fiche['nom']} · ".($fiche['telephone'] ?? 'sans téléphone')." · {$fiche['id']}");
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
        $comptes = count($fiche['comptes']);

        return implode(', ', $liste ?: ['aucune']).($comptes > 0 ? " — {$comptes} compte(s)" : '');
    }

    private function blocages(array $paire): string
    {
        $lignes = array_column($paire['blocages'], 'detail');
        if ($paire['rattachement'] !== null) {
            $lignes[] = 'Rattachées au compte '.$paire['rattachement']['compte'].' si fusion : '
                .implode(', ', array_map(fn (array $f) => $f['fonction'].($f['reference'] ? " {$f['reference']}" : " {$f['id']}"), $paire['rattachement']['fonctions']));
        }

        return implode(' | ', $lignes ?: ['aucun']);
    }

    /** @param  list<array<string, mixed>>  $paires */
    private function exporter(string $chemin, array $paires): void
    {
        $flux = fopen($chemin, 'w');
        fwrite($flux, "\xEF\xBB\xBF");
        fputcsv($flux, ['type', 'criteres', 'a_id', 'a_nom', 'a_telephone', 'a_ville', 'a_fonctions', 'b_id', 'b_nom', 'b_telephone', 'b_ville', 'b_fonctions', 'blocages', 'rattachement'], ';');
        foreach ($paires as $p) {
            fputcsv($flux, [
                $p['type'], $this->criteres($p),
                $p['a']['id'], $p['a']['nom'], $p['a']['telephone'], $p['a']['ville'], $this->fonctions($p['a']),
                $p['b']['id'], $p['b']['nom'], $p['b']['telephone'], $p['b']['ville'], $this->fonctions($p['b']),
                implode(' | ', array_column($p['blocages'], 'detail')),
                $p['rattachement'] === null ? '' : $this->blocages(['blocages' => [], 'rattachement' => $p['rattachement']]),
            ], ';');
        }
        fclose($flux);
    }
}
