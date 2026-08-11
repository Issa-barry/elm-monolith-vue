<?php

namespace App\Http\Controllers;

use App\Enums\StatutImportFlotte;
use App\Http\Requests\StoreImportFlotteRequest;
use App\Models\ImportFlotte;
use App\Services\ImportFlotte\ImportFlotteExecutor;
use App\Services\ImportFlotte\ImportFlotteParser;
use App\Services\ImportFlotte\ImportFlotteTemplateExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ImportFlotteController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', ImportFlotte::class);

        $imports = ImportFlotte::with('user:id,nom,prenom')
            ->where('organization_id', auth()->user()->organization_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ImportFlotte $i) => $this->toRow($i));

        return Inertia::render('ImportsFlotte/Index', [
            'imports' => $imports,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ImportFlotte::class);

        return Inertia::render('ImportsFlotte/Create');
    }

    public function store(StoreImportFlotteRequest $request): RedirectResponse
    {
        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403);

        $fichier = $request->file('fichier');
        $nomOriginal = $fichier->getClientOriginalName();
        $extension = $fichier->getClientOriginalExtension() ?: 'xlsx';
        $chemin = "imports-flotte/{$orgId}/".Str::uuid().'.'.$extension;

        $fichier->storeAs(dirname($chemin), basename($chemin), 'local');

        $import = ImportFlotte::create([
            'organization_id' => $orgId,
            'user_id' => $request->user()->id,
            'fichier_original' => $nomOriginal,
            'fichier_path' => $chemin,
            'statut' => StatutImportFlotte::ANALYSE->value,
        ]);

        $this->analyser($import);

        return redirect()->route('imports-flotte.show', $import)
            ->with('success', 'Fichier analysé. Vérifiez l\'aperçu avant de confirmer.');
    }

    public function show(ImportFlotte $importFlotte): Response
    {
        $this->authorize('view', $importFlotte);

        return Inertia::render('ImportsFlotte/Show', [
            'record' => $this->toDetail($importFlotte),
        ]);
    }

    /**
     * Traitement synchrone (pas de file d'attente) : le volume réel de ces
     * imports (dizaines à centaines de lignes, plafonné par
     * ImportFlotteParser::MAX_LIGNES) reste rapide dans le cycle de la requête
     * HTTP elle-même — inutile d'ajouter la complexité d'un worker/cron pour ça.
     */
    public function confirm(ImportFlotte $importFlotte, ImportFlotteExecutor $executor): RedirectResponse
    {
        $this->authorize('view', $importFlotte);

        // Update conditionnel atomique (et non lecture d'estPret() puis écriture
        // séparée) : deux confirmations quasi simultanées (double-clic, deux
        // onglets) ne peuvent pas toutes les deux passer — une seule requête UPDATE
        // peut affecter la ligne tant qu'elle est encore au statut "analyse".
        $misAJour = ImportFlotte::where('id', $importFlotte->id)
            ->where('statut', StatutImportFlotte::ANALYSE->value)
            ->where('nb_groupes_erreur', 0)
            ->update(['statut' => StatutImportFlotte::EN_COURS->value, 'demarre_le' => now()]);

        abort_unless($misAJour === 1, 422, "Cet import n'est pas prêt à être confirmé (déjà confirmé, ou groupes en erreur).");

        $this->traiter($importFlotte->fresh(), $executor);

        return redirect()->route('imports-flotte.show', $importFlotte)
            ->with('success', 'Import terminé.');
    }

    /**
     * Relance un import échoué. Sûr par construction : ImportFlotteExecutor
     * s'exécute dans une transaction globale, donc un échec n'a rien laissé
     * en base — relancer équivaut à une nouvelle confirmation à partir du
     * fichier déjà stocké.
     */
    public function retry(ImportFlotte $importFlotte, ImportFlotteExecutor $executor): RedirectResponse
    {
        $this->authorize('view', $importFlotte);

        $misAJour = ImportFlotte::where('id', $importFlotte->id)
            ->where('statut', StatutImportFlotte::ECHOUE->value)
            ->update(['statut' => StatutImportFlotte::EN_COURS->value, 'demarre_le' => now()]);

        abort_unless($misAJour === 1, 422, "Cet import n'est pas en échec.");

        $this->traiter($importFlotte->fresh(), $executor);

        return redirect()->route('imports-flotte.show', $importFlotte)
            ->with('success', 'Nouvelle tentative terminée.');
    }

    public function template()
    {
        abort_if(! auth()->user()->can('imports-flotte.create'), 403);

        return Excel::download(new ImportFlotteTemplateExport, 'template-import-flotte.xlsx');
    }

    private function analyser(ImportFlotte $import): void
    {
        $absolutePath = Storage::disk('local')->path($import->fichier_path);
        $analyse = app(ImportFlotteParser::class)->analyser($absolutePath, $import->organization_id);

        $groupes = $analyse['groupes'];
        $nbErreur = count(array_filter($groupes, fn ($g) => $g['statut'] === 'erreur'));

        $import->update([
            'statut' => StatutImportFlotte::ANALYSE->value,
            'rapport' => $analyse,
            'nb_lignes_total' => $analyse['nb_lignes_total'],
            'nb_groupes_valides' => count($groupes) - $nbErreur,
            'nb_groupes_erreur' => $nbErreur,
            'analyse_le' => now(),
        ]);
    }

    private function traiter(ImportFlotte $import, ImportFlotteExecutor $executor): void
    {
        try {
            $resultat = $executor->executer($import);
        } catch (Throwable $e) {
            report($e);

            // Message générique côté utilisateur : le détail technique (ex.
            // message SQL brut avec noms de colonnes/valeurs) ne doit pas
            // fuiter dans l'interface — il reste consultable dans les logs
            // serveur via report() ci-dessus.
            $import->update([
                'statut' => StatutImportFlotte::ECHOUE->value,
                'rapport' => ['erreur_fatale' => "Une erreur inattendue est survenue pendant l'import. Aucune donnée n'a été enregistrée. Contactez le support si le problème persiste."],
                'termine_le' => now(),
            ]);

            return;
        }

        $groupes = $resultat['rapport']['groupes'];
        $nbErreur = count(array_filter($groupes, fn ($g) => $g['statut'] === 'erreur'));

        if ($resultat['succes']) {
            $import->update([
                'statut' => StatutImportFlotte::TERMINE->value,
                'rapport' => $resultat['rapport'],
                'nb_groupes_valides' => count($groupes),
                'nb_groupes_erreur' => 0,
                'nb_proprietaires_crees' => $resultat['compteurs']['proprietaires_crees'],
                'nb_vehicules_crees' => $resultat['compteurs']['vehicules_crees'],
                'nb_livreurs_crees' => $resultat['compteurs']['livreurs_crees'],
                'nb_equipes_creees' => $resultat['compteurs']['equipes_creees'],
                'termine_le' => now(),
            ]);
        } else {
            $import->update([
                'statut' => StatutImportFlotte::ECHOUE->value,
                'rapport' => $resultat['rapport'],
                'nb_groupes_valides' => count($groupes) - $nbErreur,
                'nb_groupes_erreur' => $nbErreur,
                'termine_le' => now(),
            ]);
        }
    }

    private function toRow(ImportFlotte $i): array
    {
        return [
            'id' => $i->id,
            'fichier_original' => $i->fichier_original,
            'statut' => $i->statut->value,
            'statut_label' => $i->statut->label(),
            'nb_groupes_valides' => $i->nb_groupes_valides,
            'nb_groupes_erreur' => $i->nb_groupes_erreur,
            'nb_proprietaires_crees' => $i->nb_proprietaires_crees,
            'nb_vehicules_crees' => $i->nb_vehicules_crees,
            'nb_livreurs_crees' => $i->nb_livreurs_crees,
            'nb_equipes_creees' => $i->nb_equipes_creees,
            'utilisateur' => $i->user ? trim("{$i->user->prenom} {$i->user->nom}") : null,
            'created_at' => $i->created_at?->format('d/m/Y H:i'),
            'termine_le' => $i->termine_le?->format('d/m/Y H:i'),
        ];
    }

    private function toDetail(ImportFlotte $i): array
    {
        return array_merge($this->toRow($i), [
            'peut_confirmer' => $i->estPret(),
            'rapport' => $i->rapport,
        ]);
    }
}
