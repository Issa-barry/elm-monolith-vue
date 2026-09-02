<?php

namespace App\Http\Controllers;

use App\Enums\StatutImportVehiculesMaj;
use App\Http\Requests\StoreImportVehiculesMajRequest;
use App\Models\ImportVehiculesMaj;
use App\Services\ImportVehiculesMaj\ImportVehiculesMajExecutor;
use App\Services\ImportVehiculesMaj\ImportVehiculesMajParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Import de mise à jour en masse des véhicules (site, capacités, usages vente/logistique) —
 * entièrement séparé d'ImportFlotteController, qui reste le seul chemin de création de
 * véhicules. Une immatriculation introuvable dans l'organisation courante est une erreur
 * bloquante pour sa ligne, jamais une création (cf. ImportVehiculesMajParser).
 */
class ImportVehiculesMajController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', ImportVehiculesMaj::class);

        $imports = ImportVehiculesMaj::with(['user:id,personne_id', 'user.personne'])
            ->where('organization_id', auth()->user()->organization_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ImportVehiculesMaj $i) => $this->toRow($i));

        return Inertia::render('ImportsVehiculesMaj/Index', [
            'imports' => $imports,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ImportVehiculesMaj::class);

        return Inertia::render('ImportsVehiculesMaj/Create');
    }

    public function store(StoreImportVehiculesMajRequest $request): RedirectResponse
    {
        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403);

        $fichier = $request->file('fichier');
        $nomOriginal = $fichier->getClientOriginalName();
        $extension = $fichier->getClientOriginalExtension() ?: 'xlsx';
        $chemin = "imports-vehicules-maj/{$orgId}/".Str::uuid().'.'.$extension;

        $fichier->storeAs(dirname($chemin), basename($chemin), 'local');

        $import = ImportVehiculesMaj::create([
            'organization_id' => $orgId,
            'user_id' => $request->user()->id,
            'fichier_original' => $nomOriginal,
            'fichier_path' => $chemin,
            'statut' => StatutImportVehiculesMaj::ANALYSE->value,
        ]);

        $analyseReussie = $this->analyser($import);

        return redirect()->route('vehicules.imports-maj.show', $import)->with(
            $analyseReussie ? 'success' : 'error',
            $analyseReussie
                ? "Fichier analysé. Vérifiez l'aperçu avant de confirmer."
                : "Le fichier n'a pas pu être analysé — voir le détail ci-dessous."
        );
    }

    public function show(ImportVehiculesMaj $importVehiculesMaj): Response
    {
        $this->authorize('view', $importVehiculesMaj);

        return Inertia::render('ImportsVehiculesMaj/Show', [
            'record' => $this->toDetail($importVehiculesMaj),
        ]);
    }

    /**
     * Traitement synchrone (pas de file d'attente) — même choix qu'ImportFlotteController /
     * ImportProduitsController, volume comparable (plafonné à 500 lignes par
     * ImportVehiculesMajParser::MAX_LIGNES).
     */
    public function confirm(ImportVehiculesMaj $importVehiculesMaj, ImportVehiculesMajExecutor $executor): RedirectResponse
    {
        $this->authorize('confirm', $importVehiculesMaj);

        // Update conditionnel atomique (et non lecture d'estPret() puis écriture séparée) : deux
        // confirmations quasi simultanées (double-clic, deux onglets) ne peuvent pas toutes les
        // deux passer — même mécanisme qu'ImportFlotteController::confirm().
        $misAJour = ImportVehiculesMaj::where('id', $importVehiculesMaj->id)
            ->where('statut', StatutImportVehiculesMaj::ANALYSE->value)
            ->where('nb_lignes_erreur', 0)
            ->update(['statut' => StatutImportVehiculesMaj::EN_COURS->value, 'demarre_le' => now()]);

        abort_unless($misAJour === 1, 422, "Cet import n'est pas prêt à être confirmé (déjà confirmé, ou lignes en erreur).");

        $statutFinal = $this->traiter($importVehiculesMaj->fresh(), $executor);

        return redirect()->route('vehicules.imports-maj.show', $importVehiculesMaj)
            ->with(...$this->messageDeStatut($statutFinal, $importVehiculesMaj->fresh()));
    }

    /**
     * Relance un import échoué. Sûr par construction : ImportVehiculesMajExecutor s'exécute
     * dans une transaction globale, donc un échec n'a rien laissé en base — relancer équivaut à
     * une nouvelle confirmation à partir du fichier déjà stocké.
     */
    public function retry(ImportVehiculesMaj $importVehiculesMaj, ImportVehiculesMajExecutor $executor): RedirectResponse
    {
        $this->authorize('retry', $importVehiculesMaj);

        $misAJour = ImportVehiculesMaj::where('id', $importVehiculesMaj->id)
            ->where('statut', StatutImportVehiculesMaj::ECHOUE->value)
            ->update(['statut' => StatutImportVehiculesMaj::EN_COURS->value, 'demarre_le' => now()]);

        abort_unless($misAJour === 1, 422, "Cet import n'est pas en échec.");

        $statutFinal = $this->traiter($importVehiculesMaj->fresh(), $executor);

        return redirect()->route('vehicules.imports-maj.show', $importVehiculesMaj)
            ->with(...$this->messageDeStatut($statutFinal, $importVehiculesMaj->fresh()));
    }

    /** @return array{0: string, 1: string} [clé de flash, message] */
    private function messageDeStatut(StatutImportVehiculesMaj $statut, ImportVehiculesMaj $import): array
    {
        return match ($statut) {
            StatutImportVehiculesMaj::TERMINE => ['success', sprintf(
                'Import terminé : %d véhicule%s mis à jour.',
                $import->nb_vehicules_mis_a_jour ?? 0,
                ($import->nb_vehicules_mis_a_jour ?? 0) > 1 ? 's' : ''
            )],
            default => ['error', "L'import a échoué — voir le détail ci-dessous."],
        };
    }

    /** @return bool true si l'analyse a pu être menée à bien (avec ou sans erreurs de contenu) */
    private function analyser(ImportVehiculesMaj $import): bool
    {
        $absolutePath = Storage::disk('local')->path($import->fichier_path);

        try {
            $analyse = app(ImportVehiculesMajParser::class)->analyserFichier($absolutePath, $import->organization_id);
        } catch (Throwable $e) {
            report($e);

            // Fichier illisible (corrompu, ou renommé en .xlsx sans en être un) : jamais de 500
            // brute ni de détail technique exposé — l'import est marqué en échec explicite,
            // consultable comme n'importe quel autre échec (cf. traiter()).
            $import->update([
                'statut' => StatutImportVehiculesMaj::ECHOUE->value,
                'erreur_technique' => "Le fichier n'a pas pu être lu. Vérifiez qu'il s'agit bien d'un fichier Excel valide (.xlsx ou .xls), non corrompu.",
                'termine_le' => now(),
            ]);

            return false;
        }

        $compteurs = $this->compteurs($analyse['lignes']);

        $import->update([
            'statut' => StatutImportVehiculesMaj::ANALYSE->value,
            'rapport' => $analyse,
            'nb_lignes_total' => $analyse['nb_lignes_total'],
            'nb_lignes_maj' => $compteurs['mise_a_jour'],
            'nb_lignes_inchange' => $compteurs['inchange'],
            'nb_lignes_erreur' => $compteurs['erreur'],
            'analyse_le' => now(),
        ]);

        return true;
    }

    private function traiter(ImportVehiculesMaj $import, ImportVehiculesMajExecutor $executor): StatutImportVehiculesMaj
    {
        try {
            $resultat = $executor->executer($import);
        } catch (Throwable $e) {
            report($e);

            $import->update([
                'statut' => StatutImportVehiculesMaj::ECHOUE->value,
                'erreur_technique' => "Une erreur inattendue est survenue pendant l'import. Aucune donnée n'a été enregistrée. Contactez le support si le problème persiste.",
                'termine_le' => now(),
            ]);

            return StatutImportVehiculesMaj::ECHOUE;
        }

        if (! $resultat['succes']) {
            $rapport = $resultat['rapport'];
            $compteurs = $this->compteurs($rapport['lignes']);

            $import->update([
                'statut' => StatutImportVehiculesMaj::ECHOUE->value,
                'rapport' => $rapport,
                'erreur_technique' => 'Le fichier contient des lignes en erreur — vérifiez que les données n\'ont pas changé depuis l\'aperçu.',
                'nb_lignes_maj' => $compteurs['mise_a_jour'],
                'nb_lignes_inchange' => $compteurs['inchange'],
                'nb_lignes_erreur' => $compteurs['erreur'],
                'termine_le' => now(),
            ]);

            return StatutImportVehiculesMaj::ECHOUE;
        }

        $import->update([
            'statut' => StatutImportVehiculesMaj::TERMINE->value,
            'rapport' => $resultat['rapport'],
            'nb_vehicules_mis_a_jour' => $resultat['compteurs']['mis_a_jour'],
            'termine_le' => now(),
        ]);

        return StatutImportVehiculesMaj::TERMINE;
    }

    /** @return array{mise_a_jour: int, inchange: int, erreur: int} */
    private function compteurs(array $lignes): array
    {
        $compteurs = ['mise_a_jour' => 0, 'inchange' => 0, 'erreur' => 0];
        foreach ($lignes as $ligne) {
            $compteurs[$ligne['statut']] = ($compteurs[$ligne['statut']] ?? 0) + 1;
        }

        return $compteurs;
    }

    private function toRow(ImportVehiculesMaj $i): array
    {
        return [
            'id' => $i->id,
            'fichier_original' => $i->fichier_original,
            'statut' => $i->statut->value,
            'statut_label' => $i->statut->label(),
            'nb_lignes_total' => $i->nb_lignes_total,
            'nb_lignes_maj' => $i->nb_lignes_maj,
            'nb_lignes_inchange' => $i->nb_lignes_inchange,
            'nb_lignes_erreur' => $i->nb_lignes_erreur,
            'nb_vehicules_mis_a_jour' => $i->nb_vehicules_mis_a_jour,
            'utilisateur' => $i->user ? trim("{$i->user->prenom} {$i->user->nom}") : null,
            'created_at' => $i->created_at?->format('d/m/Y H:i'),
            'termine_le' => $i->termine_le?->format('d/m/Y H:i'),
        ];
    }

    private function toDetail(ImportVehiculesMaj $i): array
    {
        return array_merge($this->toRow($i), [
            'peut_confirmer' => $i->estPret(),
            'rapport' => $i->rapport,
            'erreur_technique' => $i->erreur_technique,
        ]);
    }
}
