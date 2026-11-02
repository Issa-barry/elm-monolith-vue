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
 * Page « Contrat de travail » d'un contrat (ADR 0044, 0048) : état du PDF, informations reprises
 * automatiquement et versions générées (voir, télécharger, marquer signé). Plus de formulaire de
 * génération : le PDF est produit à la création et à la modification du contrat, et remis à jour
 * à l'ouverture de cette page tant que la dernière version n'est pas signée. Lecture : accès à la
 * liste + droit de voir les salaires ; mise à jour et signature : accès en écriture.
 */
class ShowDocumentContratController extends Controller
{
    use VerifieAccesContrat;

    public function __invoke(Contrat $contrat, DocumentContratService $documents, ModeleContratService $modeles): Response
    {
        $user = request()->user();
        $this->verifierAccesContrat($user, $contrat);

        $peutGenerer = $this->aAccesContrat($user, $contrat, ecriture: true);
        // Une information a changé depuis (dossier, primes, PDG) : la version non signée est remise
        // à jour avant affichage, jamais une version signée.
        if ($peutGenerer && $contrat->statut_contrat === StatutContrat::ACTIF) {
            $documents->genererAutomatiquement($contrat, $user);
        }

        $employe = $documents->employe($contrat);
        $modele = $modeles->pourType($contrat->organization_id, $contrat->type_contrat);
        $versions = $contrat->documents()->with(['auteur.personne', 'signataireEnregistreur.personne'])->get();

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
            'etat' => $documents->etat($contrat),
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
            'avantages' => [],
        ];

        // Informations reprises (lecture seule) : celles que le modèle actif utilise.
        if ($modele->estActif()) {
            $textes = [$modele->titre, ...collect($modele->articles)->flatMap(fn (array $a) => [$a['titre'], $a['texte']])->all()];
            $valeurs = $documents->valeursDossier($contrat, Organization::findOrFail($contrat->organization_id));

            $props['variables'] = collect(VariablesContrat::extraire($textes))
                ->filter(fn (string $cle) => (VariablesContrat::CATALOGUE[$cle]['source'] ?? null) === 'dossier' && ! in_array($cle, ['document.date', 'document.lieu_signature'], true))
                ->map(fn (string $cle) => ['cle' => $cle, 'libelle' => VariablesContrat::libelleCourt($cle), 'valeur' => $valeurs[$cle] ?? null])
                ->values();
            $props['avantages'] = $documents->avantagesDisponibles($contrat)
                ->map(fn (AffectationRemuneration $a) => $documents->texteAvantage($a))
                ->values();
        }

        return Inertia::render('Contrats/Document', $props);
    }
}
