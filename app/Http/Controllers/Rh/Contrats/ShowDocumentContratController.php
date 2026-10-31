<?php

namespace App\Http\Controllers\Rh\Contrats;

use App\Enums\StatutContrat;
use App\Http\Controllers\Controller;
use App\Models\AffectationRemuneration;
use App\Models\Contrat;
use App\Models\DocumentContrat;
use App\Models\Organization;
use App\Services\Rh\DocumentContratService;
use App\Services\Rh\ModeleContratService;
use App\Support\Rh\Contrats\VariablesContrat;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page « Contrat de travail » d'un contrat (ADR 0044) : données reprises, champs à compléter,
 * primes et avantages à retenir, puis historique des versions générées. La lecture suit l'accès à
 * la liste et exige de voir les salaires ; la génération exige l'accès en écriture (bouton masqué
 * sinon).
 */
class ShowDocumentContratController extends Controller
{
    use VerifieAccesContrat;

    public function __invoke(Contrat $contrat, DocumentContratService $documents, ModeleContratService $modeles): Response
    {
        $user = request()->user();
        $this->verifierAccesContrat($user, $contrat);

        $peutGenerer = $this->aAccesContrat($user, $contrat, ecriture: true);
        $employe = $documents->employe($contrat);
        $modele = $modeles->pourType($contrat->organization_id, $contrat->type_contrat);
        $versions = $contrat->documents()->with(['auteur.personne', 'signataireEnregistreur.personne'])->get();
        $precedente = $versions->first();

        $props = [
            'contrat' => [
                'id' => $contrat->id,
                'employe_id' => $contrat->employe_id,
                'employe_nom_complet' => $employe ? trim("{$employe->prenom} {$employe->nom}") : null,
                'employe_matricule' => $employe?->matricule,
                'type_contrat' => $contrat->type_contrat->value,
                'type_contrat_label' => $contrat->type_contrat->label(),
                'statut_contrat' => $contrat->statut_contrat->value,
                'statut_contrat_label' => $contrat->statut_contrat->label(),
                'est_actif' => $contrat->statut_contrat === StatutContrat::ACTIF,
                'date_debut' => $contrat->date_debut?->format('d/m/Y'),
                'date_fin' => $contrat->date_fin?->format('d/m/Y'),
            ],
            'modele' => [
                'titre' => $modele->titre,
                'statut' => $modele->statut->value,
                'statut_label' => $modele->statut->label(),
                'actif' => $modele->estActif(),
                'revision' => $modele->revision,
            ],
            'versions' => $versions->map(fn (DocumentContrat $d) => [
                'id' => $d->id,
                'version' => $d->version,
                'statut' => $d->statut->value,
                'statut_label' => $d->statut->label(),
                'genere_le' => $d->created_at->format('d/m/Y à H:i'),
                'genere_le_iso' => $d->created_at->format('Y-m-d'),
                'genere_par' => $d->auteur?->name,
                'signe_le' => $d->signe_le?->format('d/m/Y'),
                'signature_enregistree_par' => $d->signataireEnregistreur?->name,
                'modele_revision' => $d->donnees['modele']['revision'] ?? null,
            ])->values(),
            'peut_generer' => $peutGenerer,
            'variables' => [],
            'champs' => (object) [],
            'champs_utilises' => [],
            'avantages' => [],
            'avantages_retenus' => [],
        ];

        // Données et champs seulement pour qui peut générer : elles contiennent le salaire.
        if ($peutGenerer && $modele->estActif()) {
            $textes = [$modele->titre, ...collect($modele->articles)->flatMap(fn (array $a) => [$a['titre'], $a['texte']])->all()];
            $utilisees = VariablesContrat::extraire($textes);
            $valeurs = $documents->valeursDossier($contrat, Organization::findOrFail($contrat->organization_id));
            $champParVariable = array_flip(VariablesContrat::CHAMPS_SAISIS);

            $props['variables'] = collect($utilisees)
                ->filter(fn (string $cle) => (VariablesContrat::CATALOGUE[$cle]['source'] ?? null) === 'dossier' && $cle !== 'document.date')
                ->map(fn (string $cle) => ['cle' => $cle, 'libelle' => VariablesContrat::libelle($cle), 'valeur' => $valeurs[$cle] ?? null])
                ->values();
            $props['champs_utilises'] = collect($utilisees)
                ->filter(fn (string $cle) => isset($champParVariable[$cle]))
                ->map(fn (string $cle) => ['champ' => $champParVariable[$cle], 'libelle' => VariablesContrat::libelle($cle)])
                ->values();
            $props['champs'] = $documents->champsProposes($contrat);
            $props['avantages'] = $documents->avantagesDisponibles($contrat)
                ->map(fn (AffectationRemuneration $a) => ['id' => $a->id, 'texte' => $documents->texteAvantage($a)])
                ->values();
            $props['avantages_retenus'] = collect($precedente?->donnees['avantages'] ?? [])->pluck('affectation_id')->values();
            $props['utilise_avantages'] = in_array('contrat.avantages', $utilisees, true);
        }

        return Inertia::render('Contrats/Document', $props);
    }
}
