<?php

namespace App\Services\ImportRh;

use App\Enums\StatutImportRh;
use App\Models\ImportRh;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Cycle d'un import RH : analyse, exécution, suppression du fichier déposé. Le fichier contient des
 * salaires : il n'est gardé que le temps de la confirmation, puis supprimé dès que l'import est
 * terminé, échoué ou abandonné (décision D5). Le rapport reste pour la traçabilité.
 */
class ImportRhService
{
    public function __construct(
        private readonly ImportRhParser $parser,
        private readonly ImportRhExecutor $executor,
    ) {}

    public function analyser(ImportRh $import, User $acteur): bool
    {
        try {
            $analyse = $this->parser->analyserFichier(Storage::disk('local')->path($import->fichier_path), $acteur);
        } catch (Throwable $e) {
            $this->signaler($e, $import);
            $this->echouer($import, "Le fichier n'a pas pu être lu. Vérifiez qu'il s'agit bien d'un fichier Excel valide (.xlsx ou .xls), non corrompu.");

            return false;
        }

        if ($analyse['erreur_globale'] !== null) {
            $this->echouer($import, $analyse['erreur_globale'], $analyse);

            return false;
        }

        $import->update([
            'statut' => StatutImportRh::ANALYSE->value,
            'rapport' => $analyse,
            'nb_lignes_total' => $analyse['nb_lignes_total'],
            ...$this->compteurs($analyse['lignes']),
            'analyse_le' => now(),
        ]);

        return true;
    }

    /** Appelée après le passage atomique au statut « en cours » (ConfirmImportRhController). */
    public function traiter(ImportRh $import, User $acteur): StatutImportRh
    {
        try {
            $resultat = $this->executor->executer($import, $acteur);
        } catch (Throwable $e) {
            $this->signaler($e, $import);
            $this->echouer($import, "Une erreur inattendue est survenue pendant l'import. Aucune donnée n'a été enregistrée.");

            return StatutImportRh::ECHOUE;
        }

        if (! $resultat['succes']) {
            $this->echouer(
                $import,
                $resultat['rapport']['erreur_globale']
                    ?? 'Des lignes sont en erreur au moment de confirmer (les données ont changé depuis l\'aperçu). Aucune donnée n\'a été enregistrée.',
                $resultat['rapport'],
            );

            return StatutImportRh::ECHOUE;
        }

        $import->update([
            'statut' => StatutImportRh::TERMINE->value,
            'rapport' => $resultat['rapport'],
            'nb_employes_mis_a_jour' => $resultat['compteurs']['employes'],
            'nb_contrats_crees' => $resultat['compteurs']['contrats'],
            'termine_le' => now(),
        ]);
        $this->supprimerFichier($import);

        return StatutImportRh::TERMINE;
    }

    public function abandonner(ImportRh $import): void
    {
        $import->update(['statut' => StatutImportRh::ABANDONNE->value, 'termine_le' => now()]);
        $this->supprimerFichier($import);
    }

    private function echouer(ImportRh $import, string $message, ?array $rapport = null): void
    {
        $import->update([
            'statut' => StatutImportRh::ECHOUE->value,
            'erreur_technique' => $message,
            'termine_le' => now(),
            ...($rapport !== null ? ['rapport' => $rapport, ...$this->compteurs($rapport['lignes'])] : []),
        ]);
        $this->supprimerFichier($import);
    }

    /**
     * Une erreur SQL contient la requête et ses valeurs (un salaire possible) : elle n'est jamais
     * journalisée telle quelle, seulement son code et l'import concerné.
     */
    private function signaler(Throwable $e, ImportRh $import): void
    {
        report($e instanceof QueryException
            ? new RuntimeException(sprintf('Import RH %s : erreur SQL %s (détail non journalisé : données salariales possibles).', $import->id, $e->getCode()))
            : $e);
    }

    private function supprimerFichier(ImportRh $import): void
    {
        if ($import->fichier_path !== null) {
            Storage::disk('local')->delete($import->fichier_path);
            $import->update(['fichier_path' => null]);
        }
    }

    /** @return array{nb_lignes_maj: int, nb_lignes_inchange: int, nb_lignes_erreur: int} */
    private function compteurs(array $lignes): array
    {
        $par = collect($lignes)->countBy('statut');

        return [
            'nb_lignes_maj' => $par->get('mise_a_jour', 0),
            'nb_lignes_inchange' => $par->get('inchange', 0),
            'nb_lignes_erreur' => $par->get('erreur', 0),
        ];
    }
}
