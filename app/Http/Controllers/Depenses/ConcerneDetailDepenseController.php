<?php

namespace App\Http\Controllers\Depenses;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Depense;
use App\Models\Employe;
use App\Models\Livreur;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConcerneDetailDepenseController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Depense::class);

        $type = $request->query('type');
        $id = $request->query('id');
        $orgId = auth()->user()->organization_id;

        $detail = match ($type) {
            'proprietaire' => $this->buildProprietaireDetail($id, $orgId),
            'livreur' => $this->buildLivreurDetail($id, $orgId),
            'employe' => $this->buildEmployeDetail($id, $orgId),
            'prestataire' => $this->buildPrestataireDetail($id, $orgId),
            'client' => $this->buildClientDetail($id, $orgId),
            default => null,
        };

        if (! $detail) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json($detail);
    }

    private function buildProprietaireDetail(string $id, string $orgId): ?array
    {
        $p = Proprietaire::with('personne')->where('organization_id', $orgId)->find($id);
        if (! $p) {
            return null;
        }

        return [
            'type' => 'proprietaire',
            'nom' => trim("{$p->prenom} {$p->nom}"),
            'telephone' => $p->telephone ?? '—',
            'adresse' => $p->adresse ?? '—',
            'site' => '—',
        ];
    }

    private function buildLivreurDetail(string $id, string $orgId): ?array
    {
        // EquipeLivraison::nom est un accesseur PHP calculé (vehicule?->nom_vehicule), jamais
        // une colonne réelle de equipes_livraison — le sélectionner via with('equipes:id,nom')
        // provoquait une erreur SQL ("Unknown column"). On charge donc son vehicule (dont
        // l'accesseur dépend) plutôt que de tenter de sélectionner l'accesseur lui-même.
        $l = Livreur::with(['personne', 'equipes.vehicule:id,nom_vehicule'])
            ->where('organization_id', $orgId)
            ->find($id);

        if (! $l) {
            return null;
        }

        return [
            'type' => 'livreur',
            'nom' => $l->libelleAffichage(),
            'telephone' => $l->telephone ?? '—',
            'equipe' => $l->equipes->pluck('nom')->implode(', ') ?: '—',
            'site' => '—',
        ];
    }

    private function buildEmployeDetail(string $id, string $orgId): ?array
    {
        $e = Employe::with(['personne', 'site:id,nom', 'contratActif'])
            ->where('organization_id', $orgId)
            ->find($id);

        if (! $e) {
            return null;
        }

        return [
            'type' => 'employe',
            'nom' => trim("{$e->prenom} {$e->nom}"),
            'telephone' => $e->telephone ?? '—',
            'poste' => $e->type_employe?->label() ?? '—',
            'site' => $e->site?->nom ?? '—',
        ];
    }

    private function buildPrestataireDetail(string $id, string $orgId): ?array
    {
        $p = Prestataire::with(['personne', 'entrepriseTierce'])->where('organization_id', $orgId)->find($id);
        if (! $p) {
            return null;
        }

        return [
            'type' => 'prestataire',
            'nom' => $p->nom_complet ?? '—',
            'telephone' => $p->phone ?? '—',
            'poste' => $p->type_label,
            'site' => '—',
        ];
    }

    private function buildClientDetail(string $id, string $orgId): ?array
    {
        $client = Client::where('organization_id', $orgId)->find($id);
        if (! $client) {
            return null;
        }

        return [
            'type' => 'client',
            'nom' => $client->nom_complet,
            'telephone' => $client->telephone ?? '—',
            'site' => '—',
        ];
    }
}
