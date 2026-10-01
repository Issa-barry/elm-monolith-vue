<?php

namespace App\Http\Controllers;

use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistiquePart;
use App\Models\Livreur;
use App\Models\Personne;
use App\Support\Livreurs\FicheLivreurStaffData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LivreurController extends Controller
{
    /**
     * Liste lecture seule — la gestion s'effectue depuis les Équipes de livraison.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', Livreur::class);

        $livreurs = Livreur::with(['equipes.vehicule:id,nom_vehicule'])
            ->where('organization_id', auth()->user()->organization_id)
            ->orderByRaw('is_active DESC, nom_complet')
            ->get()
            ->map(fn (Livreur $l) => [
                'id' => $l->id,
                // Identité civile (nom/prenom) jamais exposée côté Eau La
                // Maman — seul nom_complet (facultatif) est affiché.
                'nom_complet' => $l->nom_complet,
                'telephone' => $l->telephone,
                'is_active' => $l->is_active,
                'has_account' => $l->user_id !== null,
                'equipes' => $l->equipes->map(fn ($e) => [
                    'id' => $e->id,
                    'vehicule_nom' => $e->vehicule?->nom_vehicule ?? '—',
                    'role' => $e->pivot->role,
                ])->values(),
            ]);

        return Inertia::render('Livreurs/Index', ['livreurs' => $livreurs]);
    }

    /**
     * Crée un livreur depuis la modal Équipe — retourne JSON.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Livreur::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom_complet' => 'nullable|string|max:150',
            'telephone' => ['required', 'string', 'max:30'],
        ], [
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
        ]);

        $telephoneNormalise = Personne::normaliserTelephone($data['telephone']);
        $existe = Livreur::where('organization_id', $orgId)
            ->whereHas('personne', fn ($q) => $q->where('telephone_normalise', $telephoneNormalise))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'telephone' => 'Ce numéro de téléphone est déjà utilisé dans votre organisation.',
            ]);
        }

        $personne = Personne::resoudreOuCreer($orgId, ['telephone' => $data['telephone']]);

        $livreur = Livreur::create([
            'nom_complet' => $data['nom_complet'] ?? null,
            'personne_id' => $personne->id,
            'organization_id' => $orgId,
            'is_active' => true,
        ]);

        return response()->json([
            'id' => $livreur->id,
            'value' => $livreur->id,
            'label' => $livreur->nom_complet,
            'nom_complet' => $livreur->nom_complet,
            'telephone' => $livreur->telephone,
            'is_active' => true,
        ], 201);
    }

    /**
     * Active / désactive un livreur depuis la fiche Équipe — retourne JSON.
     */
    public function toggle(Livreur $livreur): JsonResponse
    {
        $this->authorize('update', $livreur);

        $livreur->update(['is_active' => ! $livreur->is_active]);

        return response()->json(['is_active' => $livreur->is_active]);
    }

    /**
     * Active un livreur inactif (is_active false → true) : approbation d'un livreur auto-inscrit,
     * ou réactivation d'un livreur désactivé.
     */
    public function approuver(Request $request, Livreur $livreur): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $livreur);

        $livreur->update(['is_active' => true]);

        // La liste des livreurs appelle cette action via Inertia (router.patch), qui exige une
        // redirection : une réponse JSON y déclenche l'erreur « réponse non Inertia ».
        if ($request->wantsJson()) {
            return response()->json(['is_active' => true]);
        }

        return back()->with('success', 'Livreur activé.');
    }

    /**
     * Désactive un livreur depuis sa fiche (is_active → false), sans rien supprimer : il reste
     * rattaché à son équipe, ses commissions, factures et historiques sont conservés. Effets :
     * son compte application (s'il en a un) est bloqué sur la page d'attente
     * (RequireActiveLivreur) et il n'est plus exigé dans le partage de commission de son équipe
     * (CommissionPartageLivraisonCategorieChecker::membresRequis()). Réactivation : approuver().
     */
    public function desactiver(Request $request, Livreur $livreur): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $livreur);

        $livreur->update(['is_active' => false]);

        if ($request->wantsJson()) {
            return response()->json(['is_active' => false]);
        }

        return back()->with('success', 'Livreur désactivé.');
    }

    /**
     * Affiche la fiche d'un livreur — page d'accueil après scan QR.
     */
    public function show(Livreur $livreur, FicheLivreurStaffData $fiche): Response
    {
        $this->authorize('view', $livreur);

        $livreur->load(['equipes.vehicule:id,nom_vehicule']);

        $user = auth()->user();
        $isStaff = $user->hasAnyRole(['super_admin', 'admin_entreprise', 'manager', 'commerciale', 'comptable']);

        return Inertia::render('Livreurs/Show', [
            'livreur' => [
                'id' => $livreur->id,
                'nom_complet' => $livreur->nom_complet,
                'telephone' => $livreur->telephone,
                'is_active' => $livreur->is_active,
                'has_account' => $livreur->user_id !== null,
                'enregistre_le' => $livreur->created_at?->format('d/m/Y'),
                'equipes' => $livreur->equipes->map(fn ($e) => [
                    'id' => $e->id,
                    'vehicule_nom' => $e->vehicule?->nom_vehicule ?? '—',
                    'role' => $e->pivot->role,
                ])->values(),
            ],
            // route('comptabilite.commissions.vente.livreur', ...) sans filtre processus = « Tous les
            // processus » (Vente/Distribution client/Transfert logistique confondus, cf.
            // CommissionVenteController::showLivreur()) — remplace l'ancien
            // logistique.commissions.livreur (écran retiré le 04/09/2026, moteur legacy gelé
            // depuis le 03/09/2026, cf. docs/commissions.md), qui ne couvrait que la logistique.
            'commissions_url' => $isStaff
                ? route('comptabilite.commissions.vente.livreur', $livreur->id)
                : route('client.earnings'),
            'factures_url' => $isStaff
                ? route('factures.index', ['livreur_id' => $livreur->id])
                : null,
            'is_staff' => $isStaff,
            // Fiche complète (onglets) réservée au backoffice ; l'espace livreur garde sa vue
            // d'accès rapide.
            'fiche' => $isStaff ? $fiche->pour($livreur, $user) : null,
        ]);
    }

    /**
     * Supprime un livreur :
     *  - Suppression physique (soft) s'il n'a pas d'historique de commissions.
     *  - Désactivation logique s'il est référencé dans des commissions vente ou logistique.
     */
    public function destroy(Livreur $livreur): JsonResponse
    {
        $this->authorize('delete', $livreur);

        $hasHistory = CommissionEnveloppePart::where('beneficiaire_type', 'livreur')->where('beneficiaire_id', $livreur->id)->exists()
            || CommissionLogistiquePart::where('livreur_id', $livreur->id)->exists();

        if ($hasHistory) {
            $livreur->update(['is_active' => false]);

            return response()->json([
                'action' => 'deactivated',
                'message' => 'Ce livreur est référencé dans des commissions. Il a été désactivé plutôt que supprimé.',
                'is_active' => false,
            ]);
        }

        $livreur->delete();

        return response()->json(['action' => 'deleted']);
    }
}
