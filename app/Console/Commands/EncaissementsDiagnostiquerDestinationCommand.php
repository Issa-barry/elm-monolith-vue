<?php

namespace App\Console\Commands;

use App\Enums\EvenementComptable;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Services\Tresorerie\EncaissementDestinationDiagnostic as Diagnostic;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Où est allé l'argent des encaissements déjà enregistrés ? À exécuter avant de décider d'un
 * rattrapage : la commande dit, pour chaque encaissement, quel compte de trésorerie a été débité,
 * si ce compte correspond à un support connu et s'il est cohérent avec le moyen de paiement
 * (cf. EncaissementDestinationDiagnostic).
 *
 * Ne modifie jamais rien : lecture seule. Aucun reclassement rétroactif n'est fait (ADR 0001) — la
 * sortie sert à décider, avec un responsable, de la marche à suivre.
 */
class EncaissementsDiagnostiquerDestinationCommand extends Command
{
    protected $signature = 'encaissements:diagnostiquer-destination
        {--organization=* : ID, code ou slug d\'organisation (répétable) ; toutes si omis}
        {--depuis= : Date minimale d\'encaissement (AAAA-MM-JJ)}
        {--detail : Liste chaque encaissement à traiter}
        {--tout : Inclut aussi les encaissements conformes dans --detail et --csv}
        {--csv= : Exporte les encaissements à traiter (ou tous avec --tout) dans ce fichier CSV}';

    protected $description = 'Diagnostic en lecture seule : sur quel compte de trésorerie chaque encaissement a-t-il été comptabilisé, est-il cohérent avec le moyen de paiement, et les espèces hors caisse dédiée.';

    /** @var list<array<string, string|float>> */
    private array $export = [];

    public function handle(): int
    {
        $depuis = $this->option('depuis');
        if ($depuis && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $depuis)) {
            $this->error('--depuis attend une date AAAA-MM-JJ.');

            return self::FAILURE;
        }

        $organizations = $this->resolveOrganizations();
        if ($organizations === null) {
            return self::FAILURE;
        }
        if ($organizations->isEmpty()) {
            $this->error('Aucune organisation trouvée.');

            return self::FAILURE;
        }

        foreach ($organizations as $organization) {
            $this->newLine();
            $this->line("<fg=cyan>▸ {$organization->name}</> ({$organization->id})");
            $this->diagnostiquer($organization, $depuis ? (string) $depuis : null);
        }

        if ($csv = $this->option('csv')) {
            $this->exporterCsv((string) $csv);
        }

        return self::SUCCESS;
    }

    private function diagnostiquer(Organization $organization, ?string $depuis): void
    {
        $diagnostic = new Diagnostic;
        $tout = (bool) $this->option('tout');
        $supportsParCompte = CompteTresorerie::forOrg($organization->id)->get()->groupBy('compte_comptable_id');
        $sites = Site::where('organization_id', $organization->id)->pluck('nom', 'id');
        // Couples « agent|site » qui ont aujourd'hui une caisse dédiée active.
        $caissesActives = CompteTresorerie::forOrg($organization->id)->dediees()->actifs()->get()
            ->mapWithKeys(fn (CompteTresorerie $s) => [$s->agent_id.'|'.$s->site_id => true]);

        $total = ['nb' => 0, 'montant' => 0.0];
        /** @var array<string, array{nb: int, montant: float}> $parCategorie */
        $parCategorie = [];
        $parAgent = $parSite = $parMode = $parModeCompte = [];
        /** @var array<string, array{auteur: string, site: string, nb: int, montant: float, a_caisse: bool}> $especesParAgent */
        $especesParAgent = [];
        $lignes = [];

        EncaissementVente::query()
            ->whereHas('facture', fn (Builder $q) => $q->where('organization_id', $organization->id))
            ->when($depuis, fn (Builder $q) => $q->whereDate('date_encaissement', '>=', $depuis))
            ->with(['facture:id,organization_id,site_id,reference', 'creator'])
            ->chunkById(500, function (Collection $encaissements) use (
                $organization, $diagnostic, $supportsParCompte, $caissesActives, $sites, $tout,
                &$total, &$parCategorie, &$parAgent, &$parSite, &$parMode, &$parModeCompte, &$especesParAgent, &$lignes,
            ) {
                $pieces = PieceComptable::where('organization_id', $organization->id)
                    ->where('source_type', (new EncaissementVente)->getMorphClass())
                    ->where('type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)
                    ->whereIn('source_id', $encaissements->pluck('id'))
                    ->with('lignes.compte')
                    ->get()
                    ->keyBy('source_id');

                foreach ($encaissements as $encaissement) {
                    $resultat = $diagnostic->classer($encaissement, $pieces->get($encaissement->id), $supportsParCompte);
                    $categorie = $resultat['categorie'];
                    $aTraiter = Diagnostic::estAtraiter($categorie);
                    $montant = (float) $encaissement->montant;

                    $siteId = $encaissement->facture?->site_id;
                    $auteur = $encaissement->creator?->name ?? '— (auteur inconnu)';
                    $siteNom = $siteId ? ($sites[$siteId] ?? $siteId) : '— (facture sans site)';
                    $moyen = $encaissement->mode_paiement?->label() ?? '?';
                    $mode = $moyen.($encaissement->operateur_mobile_money ? ' / '.$encaissement->operateur_mobile_money->label() : '');

                    $total['nb']++;
                    $total['montant'] += $montant;
                    $this->cumuler($parCategorie, $categorie, $montant, $aTraiter);
                    $this->cumuler($parAgent, $auteur, $montant, $aTraiter);
                    $this->cumuler($parSite, $siteNom, $montant, $aTraiter);
                    $this->cumuler($parMode, $mode, $montant, $aTraiter);
                    $this->cumuler($parModeCompte, $moyen.' → '.($resultat['compte'] ?? '— aucun compte de trésorerie'), $montant, $aTraiter);

                    if ($categorie === Diagnostic::ESPECES_HORS_CAISSE_DEDIEE) {
                        $cle = ($encaissement->created_by ?? '-').'|'.($siteId ?? '-');
                        $especesParAgent[$cle] ??= ['auteur' => $auteur, 'site' => $siteNom, 'nb' => 0, 'montant' => 0.0, 'a_caisse' => isset($caissesActives[$cle])];
                        $especesParAgent[$cle]['nb']++;
                        $especesParAgent[$cle]['montant'] += $montant;
                    }

                    if ($aTraiter || $tout) {
                        $lignes[] = [
                            'date' => $encaissement->date_encaissement?->toDateString() ?? '',
                            'facture' => $encaissement->facture?->reference ?? '',
                            'auteur' => $auteur,
                            'site' => $siteNom,
                            'mode' => $mode,
                            'montant' => $montant,
                            'compte_debite' => $resultat['compte'] ?? '—',
                            'support' => $resultat['support'] ?? '—',
                            'statut' => $aTraiter ? 'À traiter' : 'Conforme',
                            'constat' => Diagnostic::LIBELLES[$categorie],
                        ];
                    }
                }
            });

        if ($total['nb'] === 0) {
            $this->line('Aucun encaissement.');

            return;
        }

        $this->afficherSynthese($total, $parCategorie);
        $this->afficherVentilation('Par agent (auteur de l\'encaissement)', 'Agent', $parAgent);
        $this->afficherVentilation('Par agence (site de la facture)', 'Agence', $parSite);
        $this->afficherVentilation('Par moyen de paiement', 'Moyen', $parMode);
        $this->afficherVentilation('Par moyen de paiement → compte réellement débité', 'Moyen → compte', $parModeCompte);

        if ($especesParAgent !== []) {
            $this->newLine();
            $this->line('<fg=yellow>Espèces encaissées hors caisse dédiée, par agent</> — à régulariser avec un responsable (aucun reclassement automatique) :');
            $this->table(
                ['Agent', 'Agence', 'Encaissements', 'Montant (GNF)', 'Caisse dédiée active aujourd\'hui'],
                collect($especesParAgent)->sortByDesc('montant')->map(fn (array $l) => [
                    $l['auteur'], $l['site'], $l['nb'], $this->gnf($l['montant']), $l['a_caisse'] ? 'oui' : 'NON — à créer',
                ])->values()->all(),
            );
        }

        if ($this->option('detail') && $lignes !== []) {
            $this->newLine();
            $this->table(
                ['Date', 'Facture', 'Agent', 'Agence', 'Mode', 'Montant', 'Compte débité', 'Support', 'Statut', 'Constat'],
                array_map(fn (array $l) => [
                    $l['date'], $l['facture'], $l['auteur'], $l['site'], $l['mode'],
                    $this->gnf($l['montant']), $l['compte_debite'], $l['support'], $l['statut'], $l['constat'],
                ], $lignes),
            );
        }

        foreach ($lignes as $ligne) {
            $this->export[] = ['organisation' => $organization->name, ...$ligne];
        }
    }

    /** @param  array<string, array{nb: int, montant: float, conformes: int, a_traiter: int, montant_a_traiter: float}>  $bucket */
    private function cumuler(array &$bucket, string $cle, float $montant, bool $aTraiter): void
    {
        $bucket[$cle] ??= ['nb' => 0, 'montant' => 0.0, 'conformes' => 0, 'a_traiter' => 0, 'montant_a_traiter' => 0.0];
        $bucket[$cle]['nb']++;
        $bucket[$cle]['montant'] += $montant;
        if ($aTraiter) {
            $bucket[$cle]['a_traiter']++;
            $bucket[$cle]['montant_a_traiter'] += $montant;
        } else {
            $bucket[$cle]['conformes']++;
        }
    }

    /**
     * @param  array{nb: int, montant: float}  $total
     * @param  array<string, array{nb: int, montant: float}>  $parCategorie
     */
    private function afficherSynthese(array $total, array $parCategorie): void
    {
        $this->line(sprintf('<options=bold>%d encaissement(s) analysé(s) — %s GNF</>', $total['nb'], $this->gnf($total['montant'])));

        $conformes = collect(Diagnostic::CONFORMES)->sum(fn (string $c) => $parCategorie[$c]['nb'] ?? 0);
        $montantConformes = collect(Diagnostic::CONFORMES)->sum(fn (string $c) => $parCategorie[$c]['montant'] ?? 0.0);

        $this->table(
            ['Destination de l\'argent', 'Encaissements', 'Montant (GNF)', 'Action'],
            collect(Diagnostic::LIBELLES)
                ->filter(fn (string $libelle, string $cle) => isset($parCategorie[$cle]))
                ->map(fn (string $libelle, string $cle) => [
                    $libelle,
                    $parCategorie[$cle]['nb'],
                    $this->gnf($parCategorie[$cle]['montant']),
                    Diagnostic::estAtraiter($cle) ? '⚠ à traiter' : 'OK',
                ])
                ->values()
                ->all(),
        );
        $this->line(sprintf(
            'Conformes : %d (%s GNF) — à traiter : %d (%s GNF)',
            $conformes, $this->gnf($montantConformes),
            $total['nb'] - $conformes, $this->gnf($total['montant'] - $montantConformes),
        ));
    }

    /** @param  array<string, array{nb: int, montant: float, conformes: int, a_traiter: int, montant_a_traiter: float}>  $bucket */
    private function afficherVentilation(string $titre, string $entete, array $bucket): void
    {
        $this->newLine();
        $this->line("<options=bold>{$titre}</>");
        $this->table(
            [$entete, 'Encaissements', 'Montant (GNF)', 'Conformes', 'À traiter', 'Montant à traiter (GNF)'],
            collect($bucket)->sortByDesc('montant_a_traiter')->map(fn (array $b, string $cle) => [
                $cle, $b['nb'], $this->gnf($b['montant']), $b['conformes'], $b['a_traiter'], $this->gnf($b['montant_a_traiter']),
            ])->values()->all(),
        );
    }

    private function gnf(float $montant): string
    {
        return number_format($montant, 0, ',', ' ');
    }

    private function exporterCsv(string $chemin): void
    {
        $fichier = fopen($chemin, 'w');
        if ($fichier === false) {
            $this->error("Impossible d'écrire {$chemin}.");

            return;
        }

        fwrite($fichier, "\xEF\xBB\xBF");
        fputcsv($fichier, ['Organisation', 'Date', 'Facture', 'Agent', 'Agence', 'Mode', 'Montant', 'Compte débité', 'Support', 'Statut', 'Constat'], ';');
        foreach ($this->export as $ligne) {
            fputcsv($fichier, array_values($ligne), ';');
        }
        fclose($fichier);

        $this->info(count($this->export)." encaissement(s) exporté(s) dans {$chemin}.");
    }

    private function resolveOrganizations(): ?Collection
    {
        $identifiants = $this->option('organization');
        if (empty($identifiants)) {
            return Organization::query()->get();
        }

        $organizations = Organization::query()
            ->where(function (Builder $q) use ($identifiants) {
                $q->whereIn('id', $identifiants)
                    ->orWhereIn('code', $identifiants)
                    ->orWhereIn('slug', $identifiants);
            })
            ->get();

        $trouves = $organizations->flatMap(fn (Organization $o) => array_filter([$o->id, $o->code, $o->slug]));
        $manquants = array_diff($identifiants, $trouves->all());
        if (! empty($manquants)) {
            $this->error('Organisation(s) introuvable(s) : '.implode(', ', $manquants));

            return null;
        }

        return $organizations;
    }
}
