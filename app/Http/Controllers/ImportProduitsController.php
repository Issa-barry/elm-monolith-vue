<?php

namespace App\Http\Controllers;

use App\Enums\StatutImportProduits;
use App\Http\Requests\StoreImportProduitsRequest;
use App\Models\ImportProduits;
use App\Services\ImportProduits\ImportProduitsExecutor;
use App\Services\ImportProduits\ImportProduitsParser;
use App\Services\ImportProduits\ImportProduitsRepriseExport;
use App\Services\ImportProduits\ImportProduitsTemplateExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ImportProduitsController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', ImportProduits::class);

        $imports = ImportProduits::with(['user:id,personne_id', 'user.personne'])
            ->where('organization_id', auth()->user()->organization_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ImportProduits $i) => $this->toRow($i));

        return Inertia::render('ImportsProduits/Index', [
            'imports' => $imports,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ImportProduits::class);

        return Inertia::render('ImportsProduits/Create');
    }

    public function store(StoreImportProduitsRequest $request): RedirectResponse
    {
        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403);

        $fichier = $request->file('fichier');
        $nomOriginal = $fichier->getClientOriginalName();
        $extension = $fichier->getClientOriginalExtension() ?: 'xlsx';
        $chemin = "imports-produits/{$orgId}/".Str::uuid().'.'.$extension;

        $fichier->storeAs(dirname($chemin), basename($chemin), 'local');

        $import = ImportProduits::create([
            'organization_id' => $orgId,
            'user_id' => $request->user()->id,
            'fichier_original' => $nomOriginal,
            'fichier_path' => $chemin,
            'fichier_hash' => hash_file('sha256', Storage::disk('local')->path($chemin)) ?: null,
            'statut' => StatutImportProduits::ANALYSE->value,
        ]);

        $analyseReussie = $this->analyser($import);

        return redirect()->route('produits.imports.show', $import)->with(
            $analyseReussie ? 'success' : 'error',
            $analyseReussie
                ? "Fichier analysé. Vérifiez l'aperçu avant de confirmer."
                : "Le fichier n'a pas pu être analysé — voir le détail ci-dessous."
        );
    }

    public function show(ImportProduits $importProduits): Response
    {
        $this->authorize('view', $importProduits);

        return Inertia::render('ImportsProduits/Show', [
            'record' => $this->toDetail($importProduits),
        ]);
    }

    /**
     * Traitement synchrone (pas de file d'attente) — même choix qu'ImportFlotteController,
     * volume comparable (plafonné à 500 lignes par ImportProduitsParser::MAX_LIGNES).
     */
    public function confirm(ImportProduits $importProduits, ImportProduitsExecutor $executor): RedirectResponse
    {
        $this->authorize('confirm', $importProduits);

        abort_unless($importProduits->estPret(), 422, "Cet import n'est pas prêt à être confirmé (déjà confirmé, groupes en erreur, ou fichier déjà importé).");

        // Update conditionnel atomique (et non lecture d'estPret() puis écriture séparée) : deux
        // confirmations quasi simultanées (double-clic, deux onglets) ne peuvent pas toutes les
        // deux passer — une seule requête UPDATE peut affecter la ligne tant qu'elle est encore
        // au statut "analyse" (même mécanisme qu'ImportFlotteController::confirm()).
        $misAJour = ImportProduits::where('id', $importProduits->id)
            ->where('statut', StatutImportProduits::ANALYSE->value)
            ->where('nb_lignes_erreur', 0)
            ->update(['statut' => StatutImportProduits::EN_COURS->value, 'demarre_le' => now()]);

        abort_unless($misAJour === 1, 422, "Cet import n'est pas prêt à être confirmé (déjà confirmé, ou groupes en erreur).");

        $statutFinal = $this->traiter($importProduits->fresh(), $executor);

        return redirect()->route('produits.imports.show', $importProduits)
            ->with(...$this->messageDeStatut($statutFinal, $importProduits->fresh()));
    }

    /**
     * Relance un import échoué. Sûr par construction : ImportProduitsExecutor s'exécute dans une
     * transaction globale, donc un échec n'a rien laissé en base — relancer équivaut à une
     * nouvelle confirmation à partir du fichier déjà stocké.
     */
    public function retry(ImportProduits $importProduits, ImportProduitsExecutor $executor): RedirectResponse
    {
        $this->authorize('retry', $importProduits);

        $misAJour = ImportProduits::where('id', $importProduits->id)
            ->where('statut', StatutImportProduits::ECHOUE->value)
            ->update(['statut' => StatutImportProduits::EN_COURS->value, 'demarre_le' => now()]);

        abort_unless($misAJour === 1, 422, "Cet import n'est pas en échec.");

        $statutFinal = $this->traiter($importProduits->fresh(), $executor);

        return redirect()->route('produits.imports.show', $importProduits)
            ->with(...$this->messageDeStatut($statutFinal, $importProduits->fresh()));
    }

    /** @return array{0: string, 1: string} [clé de flash, message] */
    private function messageDeStatut(StatutImportProduits $statut, ImportProduits $import): array
    {
        return match ($statut) {
            StatutImportProduits::TERMINE => ['success', $this->messageSucces($import)],
            StatutImportProduits::ANALYSE => ['error', "Les données ont changé depuis l'aperçu — vérifiez le nouvel aperçu avant de confirmer à nouveau."],
            default => ['error', "L'import a échoué — voir le détail ci-dessous."],
        };
    }

    private function messageSucces(ImportProduits $import): string
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

    public function template(Request $request)
    {
        abort_if(! $request->user()->can('imports-produits.create'), 403);

        $orgId = $request->user()->organization_id;

        return Excel::download(new ImportProduitsTemplateExport($orgId), 'modele-import-produits.xlsx');
    }

    /**
     * Fichier de reprise — régénéré à la demande depuis le rapport JSON persisté (jamais stocké
     * en tant que fichier séparé) : reste toujours cohérent avec le résultat réel, rien à
     * nettoyer. Disponible uniquement une fois l'import TERMINE (cf. brief : "générer un fichier
     * téléchargeable" après confirmation réussie).
     */
    public function reprise(ImportProduits $importProduits)
    {
        $this->authorize('view', $importProduits);

        abort_unless($importProduits->statut === StatutImportProduits::TERMINE, 404);

        return Excel::download(
            new ImportProduitsRepriseExport($importProduits),
            "reprise-import-produits-{$importProduits->id}.xlsx"
        );
    }

    /** @return bool true si l'analyse a pu être menée à bien (avec ou sans erreurs de contenu) */
    private function analyser(ImportProduits $import): bool
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

        $compteurs = $this->compteurs($analyse['lignes']);

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

    private function traiter(ImportProduits $import, ImportProduitsExecutor $executor): StatutImportProduits
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
            $compteurs = $rapport ? $this->compteurs($rapport['lignes']) : ['creation' => 0, 'mise_a_jour' => 0, 'inchange' => 0, 'erreur' => 0];

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

    /** @return array{creation: int, mise_a_jour: int, inchange: int, erreur: int} */
    private function compteurs(array $lignes): array
    {
        $compteurs = ['creation' => 0, 'mise_a_jour' => 0, 'inchange' => 0, 'erreur' => 0];
        foreach ($lignes as $ligne) {
            $compteurs[$ligne['statut']] = ($compteurs[$ligne['statut']] ?? 0) + 1;
        }

        return $compteurs;
    }

    private function toRow(ImportProduits $i): array
    {
        return [
            'id' => $i->id,
            'fichier_original' => $i->fichier_original,
            'statut' => $i->statut->value,
            'statut_label' => $i->statut->label(),
            'nb_lignes_total' => $i->nb_lignes_total,
            'nb_lignes_creation' => $i->nb_lignes_creation,
            'nb_lignes_mise_a_jour' => $i->nb_lignes_mise_a_jour,
            'nb_lignes_inchange' => $i->nb_lignes_inchange,
            'nb_lignes_erreur' => $i->nb_lignes_erreur,
            'nb_produits_crees' => $i->nb_produits_crees,
            'nb_produits_mis_a_jour' => $i->nb_produits_mis_a_jour,
            'utilisateur' => $i->user ? trim("{$i->user->prenom} {$i->user->nom}") : null,
            'created_at' => $i->created_at?->format('d/m/Y H:i'),
            'termine_le' => $i->termine_le?->format('d/m/Y H:i'),
        ];
    }

    private function toDetail(ImportProduits $i): array
    {
        return array_merge($this->toRow($i), [
            'peut_confirmer' => $i->estPret(),
            'rapport' => $i->rapport,
            'erreur_technique' => $i->erreur_technique,
        ]);
    }
}
