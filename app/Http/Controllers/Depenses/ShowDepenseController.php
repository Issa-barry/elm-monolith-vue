<?php

namespace App\Http\Controllers\Depenses;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Depense;
use App\Models\DepenseImputation;
use App\Models\Employe;
use App\Models\Livreur;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use Inertia\Inertia;
use Inertia\Response;

class ShowDepenseController extends Controller
{
    public function __invoke(Depense $depense): Response
    {
        $this->authorize('view', $depense);

        $depense->load(['depenseType', 'site', 'user', 'validateur', 'imputations']);

        $categorie = $depense->depenseType?->categorie;
        $user = auth()->user();

        $vehiculeInfo = ($depense->beneficiaire_type === 'vehicule' && $depense->beneficiaire_id)
            ? $this->resolveVehiculeInfo($depense->beneficiaire_id)
            : null;

        $concerneReelLabel = $vehiculeInfo
            ? $vehiculeInfo['concerne_reel_label']
            : $this->resoudreBeneficiaireLabel($depense->beneficiaire_type, $depense->beneficiaire_id);

        $impactMessage = $vehiculeInfo
            ? $vehiculeInfo['impact_message']
            : ($categorie?->impactMessage() ?? '');

        return Inertia::render('Depenses/Show', [
            'depense' => [
                'id' => $depense->id,
                'date_depense' => $depense->date_depense->toDateString(),
                'montant' => (float) $depense->montant,
                'montant_formatte' => number_format((float) $depense->montant, 0, ',', "\u{202F}"),
                'statut' => $depense->statut->value,
                'statut_label' => $depense->statut->label(),
                'commentaire' => $depense->commentaire,
                'motif_rejet' => $depense->motif_rejet,
                'commentaire_rejet' => $depense->commentaire_rejet,
                'justificatif_path' => $depense->justificatif_path,
                'date_validation' => $depense->date_validation?->toDateTimeString(),
                'created_at' => $depense->created_at->toDateTimeString(),
                'type_libelle' => $depense->depenseType?->libelle ?? '—',
                'categorie' => $categorie?->value ?? '',
                'categorie_label' => $categorie?->label() ?? '',
                'impact_message' => $impactMessage,
                'vehicule_nom' => $vehiculeInfo['vehicule_nom'] ?? null,
                'vehicule_immatriculation' => $vehiculeInfo['vehicule_immatriculation'] ?? null,
                'beneficiaire_label' => $concerneReelLabel,
                'site_nom' => $depense->site?->nom,
                'saisi_par' => $depense->user->name,
                'validateur' => $depense->validateur?->name,
                'imputations' => $depense->imputations->map(fn (DepenseImputation $i) => [
                    'id' => $i->id,
                    'imputation_type' => $i->imputation_type,
                    'beneficiaire_type' => $i->beneficiaire_type,
                    'beneficiaire_label' => $this->resoudreBeneficiaireLabel($i->beneficiaire_type, $i->beneficiaire_id),
                    'montant' => (float) $i->montant,
                    'periode_type' => $i->periode_type,
                    'periode_debut' => $i->periode_debut?->toDateString(),
                    'periode_fin' => $i->periode_fin?->toDateString(),
                    'statut' => $i->statut,
                ])->values(),
                'can_edit' => $user->can('update', $depense),
                'can_submit' => $user->can('view', $depense) && $depense->statut->value === 'brouillon',
                'can_validate' => $user->can('valider', $depense) && $depense->statut->value === 'soumis',
                'can_reject' => $user->can('valider', $depense) && $depense->statut->value === 'soumis',
                'can_delete' => $user->can('delete', $depense),
            ],
        ]);
    }

    private function resolveVehiculeInfo(string $vehiculeId): array
    {
        $vehicule = Vehicule::with(['proprietaire:id,personne_id', 'proprietaire.personne'])
            ->find($vehiculeId, ['id', 'nom_vehicule', 'immatriculation', 'proprietaire_id']);

        if (! $vehicule) {
            return ['vehicule_nom' => null, 'vehicule_immatriculation' => null, 'concerne_reel_label' => null, 'impact_message' => ''];
        }

        $vehiculeNom = $vehicule->nom_vehicule;
        $immatriculation = $vehicule->immatriculation;

        if ($vehicule->proprietaire_id) {
            $propNom = trim("{$vehicule->proprietaire->prenom} {$vehicule->proprietaire->nom}");

            return [
                'vehicule_nom' => $vehiculeNom,
                'vehicule_immatriculation' => $immatriculation,
                'concerne_reel_label' => $propNom,
                'impact_message' => "Cette dépense sera déduite de la commission de {$propNom}.",
            ];
        }

        return [
            'vehicule_nom' => $vehiculeNom,
            'vehicule_immatriculation' => $immatriculation,
            'concerne_reel_label' => 'Agence ELM',
            'impact_message' => 'Ce véhicule est interne ELM. La dépense sera comptabilisée comme charge entreprise.',
        ];
    }

    private function resoudreBeneficiaireLabel(?string $type, ?string $id): ?string
    {
        if (! $type || ! $id) {
            return null;
        }

        return match ($type) {
            'employe' => optional(Employe::find($id))->nom_complet,
            'livreur' => ($l = Livreur::find($id)) ? ($l->nom_complet ?? $l->telephone) : null,
            'proprietaire' => trim(optional(Proprietaire::find($id))?->prenom.' '.optional(Proprietaire::find($id))?->nom),
            'vehicule' => optional(Vehicule::find($id))->nom_vehicule,
            'prestataire' => optional(Prestataire::find($id))->nom_complet,
            'client' => optional(Client::find($id))->nom_complet,
            default => null,
        };
    }
}
