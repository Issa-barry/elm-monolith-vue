<?php

namespace App\Support\Produits\Imports;

use App\Enums\StatutImportProduits;
use App\Models\ImportProduits;
use App\Services\ImportProduits\ImportProduitsExecutor;
use App\Services\ImportProduits\ImportProduitsParser;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Transitions de statut d'un import produits (analyse initiale, traitement confirm/retry, message
 * de résultat) — extrait de ImportProduitsController::analyser()/traiter()/messageDeStatut()/
 * messageSucces()/compteurs(). `analyser()` est appelée uniquement par store() ; `traiter()` et
 * `messageDeStatut()` sont partagées par confirm() et retry() (mêmes transitions de statut,
 * qu'il s'agisse d'une première confirmation ou d'une relance après échec).
 */
final class ImportProduitsStatusProcessor
{
    /** @return bool true si l'analyse a pu être menée à bien (avec ou sans erreurs de contenu) */
    public static function analyser(ImportProduits $import): bool
    {
        $absolutePath = Storage::disk('local')->path($import->fichier_path);

        try {
            $analyse = app(ImportProduitsParser::class)->analyserFichier($absolutePath, $import->organization_id);
        } catch (Throwable $e) {
            report($e);

            // Fichier illisible (corrompu, ou renommé en .xlsx sans en être un) : jamais de 500
            // brute ni de détail technique exposé — l'import est marqué en échec explicite,
            // consultable comme n'importe quel autre échec (cf. traiter()).
            $import->update([
                'statut' => StatutImportProduits::ECHOUE->value,
                'erreur_technique' => "Le fichier n'a pas pu être lu. Vérifiez qu'il s'agit bien d'un fichier Excel valide (.xlsx ou .xls), non corrompu.",
                'termine_le' => now(),
            ]);

            return false;
        }

        $compteurs = self::compteurs($analyse['lignes']);

        $import->update([
            'statut' => StatutImportProduits::ANALYSE->value,
            'rapport' => $analyse,
            'nb_lignes_total' => $analyse['nb_lignes_total'],
            'nb_lignes_creation' => $compteurs['creation'],
            'nb_lignes_mise_a_jour' => $compteurs['mise_a_jour'],
            'nb_lignes_inchange' => $compteurs['inchange'],
            'nb_lignes_erreur' => $compteurs['erreur'],
            'analyse_le' => now(),
        ]);

        return true;
    }

    public static function traiter(ImportProduits $import, ImportProduitsExecutor $executor): StatutImportProduits
    {
        try {
            $resultat = $executor->executer($import, auth()->user());
        } catch (Throwable $e) {
            report($e);

            // Message générique côté utilisateur : le détail technique ne doit jamais fuiter
            // dans l'interface — reste consultable dans les logs serveur via report() ci-dessus.
            $import->update([
                'statut' => StatutImportProduits::ECHOUE->value,
                'erreur_technique' => "Une erreur inattendue est survenue pendant l'import. Aucune donnée n'a été enregistrée. Contactez le support si le problème persiste.",
                'termine_le' => now(),
            ]);

            return StatutImportProduits::ECHOUE;
        }

        if (! $resultat['succes']) {
            $rapport = $resultat['rapport'];
            $compteurs = $rapport ? self::compteurs($rapport['lignes']) : ['creation' => 0, 'mise_a_jour' => 0, 'inchange' => 0, 'erreur' => 0];

            // "Aperçu périmé" (le produit ciblé a changé entre l'analyse et la confirmation) :
            // jamais appliqué à l'aveugle. On revient au statut "analyse" avec le nouvel aperçu
            // fraîchement calculé, jamais à "echoue" — l'utilisateur doit relire ce qui a changé
            // puis confirmer à nouveau en toute connaissance de cause (cf. brief : contrôle de
            // concurrence entre aperçu et confirmation).
            if ($resultat['raison'] === 'apercu_perime') {
                $import->update([
                    'statut' => StatutImportProduits::ANALYSE->value,
                    'rapport' => $rapport,
                    'nb_lignes_creation' => $compteurs['creation'],
                    'nb_lignes_mise_a_jour' => $compteurs['mise_a_jour'],
                    'nb_lignes_inchange' => $compteurs['inchange'],
                    'nb_lignes_erreur' => $compteurs['erreur'],
                    'analyse_le' => now(),
                    'demarre_le' => null,
                ]);

                return StatutImportProduits::ANALYSE;
            }

            $messagesParRaison = [
                'integrite' => "Le fichier stocké a changé depuis l'analyse initiale — veuillez réimporter.",
                'fichier_deja_importe' => 'Ce fichier a déjà été importé intégralement — téléchargez son fichier de reprise plutôt que de le réimporter tel quel.',
                'permission_create' => "Vous n'avez pas la permission de créer des produits, or ce fichier contient des lignes de création.",
                'permission_update' => "Vous n'avez pas la permission de modifier des produits, or ce fichier contient des lignes de mise à jour.",
                'erreurs' => 'Le fichier contient des lignes en erreur.',
            ];

            $import->update([
                'statut' => StatutImportProduits::ECHOUE->value,
                'rapport' => $rapport,
                'erreur_technique' => $messagesParRaison[$resultat['raison']] ?? 'Import interrompu.',
                'nb_lignes_creation' => $compteurs['creation'],
                'nb_lignes_mise_a_jour' => $compteurs['mise_a_jour'],
                'nb_lignes_inchange' => $compteurs['inchange'],
                'nb_lignes_erreur' => $compteurs['erreur'],
                'termine_le' => now(),
            ]);

            return StatutImportProduits::ECHOUE;
        }

        $import->update([
            'statut' => StatutImportProduits::TERMINE->value,
            'rapport' => $resultat['rapport'],
            'nb_produits_crees' => $resultat['compteurs']['crees'],
            'nb_produits_mis_a_jour' => $resultat['compteurs']['mis_a_jour'],
            'termine_le' => now(),
        ]);

        return StatutImportProduits::TERMINE;
    }

    /** @return array{0: string, 1: string} [clé de flash, message] */
    public static function messageDeStatut(StatutImportProduits $statut, ImportProduits $import): array
    {
        return match ($statut) {
            StatutImportProduits::TERMINE => ['success', self::messageSucces($import)],
            StatutImportProduits::ANALYSE => ['error', "Les données ont changé depuis l'aperçu — vérifiez le nouvel aperçu avant de confirmer à nouveau."],
            default => ['error', "L'import a échoué — voir le détail ci-dessous."],
        };
    }

    private static function messageSucces(ImportProduits $import): string
    {
        $crees = $import->nb_produits_crees ?? 0;
        $misAJour = $import->nb_produits_mis_a_jour ?? 0;

        return sprintf(
            'Import terminé : %d produit%s créé%s, %d produit%s mis à jour.',
            $crees,
            $crees > 1 ? 's' : '',
            $crees > 1 ? 's' : '',
            $misAJour,
            $misAJour > 1 ? 's' : '',
        );
    }

    /** @return array{creation: int, mise_a_jour: int, inchange: int, erreur: int} */
    private static function compteurs(array $lignes): array
    {
        $compteurs = ['creation' => 0, 'mise_a_jour' => 0, 'inchange' => 0, 'erreur' => 0];
        foreach ($lignes as $ligne) {
            $compteurs[$ligne['statut']] = ($compteurs[$ligne['statut']] ?? 0) + 1;
        }

        return $compteurs;
    }
}
