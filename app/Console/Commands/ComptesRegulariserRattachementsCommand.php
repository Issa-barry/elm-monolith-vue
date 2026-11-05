<?php

namespace App\Console\Commands;

use App\Enums\FicheRattachable;
use App\Enums\StatutCorrespondanceCompte;
use App\Models\Organization;
use App\Services\Comptes\Data\CorrespondanceCompte;
use App\Services\Comptes\RattachementAutomatiqueService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Régularisation des rattachements manquants entre comptes et fiches métier (ADR 0056) : livreurs,
 * propriétaires, parrains, consultants et interlocuteurs d'entreprise sans `user_id` alors que la
 * personne a déjà un compte.
 *
 * Simulation par défaut : rien n'est écrit, la commande affiche le bilan par activité et le détail
 * nominatif. Avec --apply, seules les correspondances certaines sont reliées, chacune dans sa
 * transaction, re-vérifiée au moment de l'écriture et tracée dans audit_logs (`compte_rattache`,
 * `meta.automatique`, `meta.source = regularisation`). Relancer la commande ne refait rien : une
 * fiche reliée est « déjà reliée ». Aucune fiche, personne ou compte n'est créé ni supprimé.
 */
class ComptesRegulariserRattachementsCommand extends Command
{
    protected $signature = 'comptes:regulariser-rattachements
        {--organization=* : ID, code ou slug d\'organisation (répétable) ; toutes si omis}
        {--apply : Relie les correspondances certaines (sans cette option, simulation seule)}
        {--force : Avec --apply, ne demande pas de confirmation}
        {--csv= : Écrit le détail nominatif de toutes les fiches non reliées dans ce fichier}';

    protected $description = 'Détecte (et relie avec --apply) les fiches livreur, propriétaire, parrain, consultant et interlocuteur sans compte alors que la personne en a un.';

    public function handle(RattachementAutomatiqueService $service): int
    {
        $orgIds = $this->resolveOrganizationIds();
        if ($orgIds === null) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'Mode APPLICATION : seules les correspondances certaines seront reliées.' : 'Mode SIMULATION : aucune écriture.');

        /** @var list<CorrespondanceCompte> $correspondances */
        $correspondances = [];
        foreach (FicheRattachable::cases() as $type) {
            $type->requete()
                ->with(['personne', 'user'])
                ->when($orgIds !== [], fn (Builder $q) => $q->whereIn('organization_id', $orgIds))
                ->orderBy('id')
                ->chunk(500, function ($fiches) use ($service, &$correspondances) {
                    foreach ($fiches as $fiche) {
                        $correspondances[] = $service->analyser($fiche);
                    }
                });
        }

        $this->afficherBilan($correspondances);
        $this->afficherDetail($correspondances);

        if ($csv = $this->option('csv')) {
            $this->ecrireCsv((string) $csv, $correspondances);
        }

        $certaines = array_values(array_filter($correspondances, fn (CorrespondanceCompte $c) => $c->estCertaine()));

        if (! $apply) {
            $this->info(count($certaines).' rattachement(s) seraient effectués avec --apply. Les cas à vérifier restent au rattachement manuel.');

            return self::SUCCESS;
        }

        if ($certaines === []) {
            $this->info('Aucun rattachement certain à effectuer.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(count($certaines).' fiche(s) vont être reliées à un compte. Une sauvegarde de la base a-t-elle été faite, et continuer ?')) {
            $this->warn('Abandon : aucune écriture.');

            return self::FAILURE;
        }

        RattachementAutomatiqueService::avecSource('regularisation', function () use ($certaines, $service) {
            foreach ($certaines as $correspondance) {
                $service->appliquer($correspondance, 'regularisation');
            }
        });

        // Compté après coup : relier une fiche relie aussitôt les autres fiches certaines de la même
        // personne (RattachementAutomatiqueObserver), que la boucle trouve alors déjà reliées.
        $faits = array_values(array_filter(
            $certaines,
            fn (CorrespondanceCompte $c) => $c->fiche->fresh()?->user_id === $c->compte?->id,
        ));
        foreach ($faits as $correspondance) {
            Log::info('comptes:regulariser-rattachements', [
                'activite' => $correspondance->type->value,
                'fiche_id' => $correspondance->fiche->getKey(),
                'user_id' => $correspondance->compte?->id,
                'preuve' => $correspondance->preuve,
            ]);
        }

        $this->info(count($faits).' rattachement(s) effectué(s) sur '.count($certaines).' prévu(s).');

        return self::SUCCESS;
    }

    /** @param  list<CorrespondanceCompte>  $correspondances */
    private function afficherBilan(array $correspondances): void
    {
        $lignes = [];
        foreach (FicheRattachable::cases() as $type) {
            $du = array_filter($correspondances, fn (CorrespondanceCompte $c) => $c->type === $type);
            $compter = fn (StatutCorrespondanceCompte $s) => count(array_filter($du, fn (CorrespondanceCompte $c) => $c->statut === $s));
            $lies = $compter(StatutCorrespondanceCompte::DEJA_LIE);

            $lignes[] = [
                $type->label(),
                count($du),
                $lies,
                count($du) - $lies,
                $compter(StatutCorrespondanceCompte::CERTAINE),
                $compter(StatutCorrespondanceCompte::AMBIGUE),
                $compter(StatutCorrespondanceCompte::AUCUNE),
            ];
        }

        $this->table(
            ['Activité', 'Fiches examinées', 'Liens existants', 'Liens manquants', 'Correspondances certaines', 'Cas à vérifier', 'Sans compte'],
            $lignes,
        );
    }

    /** @param  list<CorrespondanceCompte>  $correspondances */
    private function afficherDetail(array $correspondances): void
    {
        $aMontrer = array_filter($correspondances, fn (CorrespondanceCompte $c) => in_array(
            $c->statut, [StatutCorrespondanceCompte::CERTAINE, StatutCorrespondanceCompte::AMBIGUE], true,
        ));

        if ($aMontrer === []) {
            return;
        }

        $this->table(
            ['Statut', 'Activité', 'Fiche', 'Nom', 'Téléphone fiche', 'Compte', 'Nom du compte', 'Preuve ou motif'],
            array_map(fn (CorrespondanceCompte $c) => $this->ligneDetail($c), array_values($aMontrer)),
        );
    }

    /** @return list<string> */
    private function ligneDetail(CorrespondanceCompte $c): array
    {
        return [
            $c->statut->label(),
            $c->type->libelleFiche(),
            (string) $c->fiche->getKey(),
            (string) ($c->fiche->personne?->nom_complet ?? ''),
            (string) ($c->fiche->personne?->telephone ?? ''),
            $c->compte?->id ?? implode(' ', $c->candidats),
            (string) ($c->compte?->personne?->nom_complet ?? ''),
            (string) ($c->preuve ?? $c->motif ?? ''),
        ];
    }

    /** @param  list<CorrespondanceCompte>  $correspondances */
    private function ecrireCsv(string $chemin, array $correspondances): void
    {
        $flux = fopen($chemin, 'w');
        if ($flux === false) {
            $this->error("Impossible d'écrire {$chemin}.");

            return;
        }

        fputcsv($flux, ['statut', 'activite', 'organisation', 'fiche_id', 'personne_id', 'nom', 'telephone_fiche', 'compte_id', 'nom_compte', 'telephone_compte', 'preuve', 'motif'], ';');
        foreach ($correspondances as $c) {
            if ($c->statut === StatutCorrespondanceCompte::DEJA_LIE) {
                continue;
            }
            fputcsv($flux, [
                $c->statut->value,
                $c->type->value,
                $c->fiche->organization_id,
                $c->fiche->getKey(),
                $c->fiche->personne_id,
                $c->fiche->personne?->nom_complet,
                $c->fiche->personne?->telephone,
                $c->compte?->id ?? implode(' ', $c->candidats),
                $c->compte?->personne?->nom_complet,
                $c->compte?->telephone,
                $c->preuve,
                $c->motif,
            ], ';');
        }
        fclose($flux);

        $this->info("Détail écrit dans {$chemin}.");
    }

    private function resolveOrganizationIds(): ?array
    {
        $identifiants = $this->option('organization');
        if (empty($identifiants)) {
            return [];
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

        return $organizations->pluck('id')->all();
    }
}
