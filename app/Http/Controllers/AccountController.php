<?php

namespace App\Http\Controllers;

use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class AccountController extends Controller
{
    /**
     * Écran "Comptes" — point d'entrée unique de la navigation pour la gestion des comptes
     * (fusion de l'ancien écran "Utilisateurs", cf. rapport du 28/08/2026). Un super_admin
     * y voit la console plateforme (tous les comptes, toutes organisations confondues,
     * bloquer/débloquer) ; tout autre acteur avec `users.read` y retrouve exactement la liste
     * organisation-scopée + les actions (créer/modifier/valider/refuser) qu'il avait déjà sur
     * `/backoffice/users` — cette route reste fonctionnelle et n'est pas dupliquée ici, on
     * délègue à UserController::indexProps() pour ne pas réécrire cette logique.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $authUser = auth()->user();

        if (! $authUser->isSuperAdmin()) {
            $org = $authUser->organization;
            abort_if(! $org || ! ModuleService::isActive(ModuleFeature::UTILISATEURS, $org), 403, 'Ce module est désactivé pour votre organisation.');

            return Inertia::render('Users/Index', app(UserController::class)->indexProps($authUser));
        }

        $clientUserIds = Client::whereNotNull('user_id')->pluck('user_id')->flip();

        $accounts = User::with(['personne', 'authIdentities', 'roles:id,name'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (User $u) use ($clientUserIds) {
                $hasStaffRole = $u->hasAnyRole(['super_admin', 'admin_entreprise', 'manager', 'commerciale', 'comptable']);

                if ($hasStaffRole) {
                    $type = 'agent';
                } elseif ($clientUserIds->has($u->id)) {
                    $type = 'client';
                } else {
                    $type = 'inscrit';
                }

                return [
                    'id' => $u->id,
                    'nom_complet' => $u->name,
                    'email' => $u->email,
                    'telephone' => $u->telephone,
                    'is_active' => $u->is_active,
                    'email_verified' => ! is_null($u->email_verified_at),
                    'type' => $type,
                    'roles' => $u->getRoleNames(),
                    'created_at' => $u->created_at?->format('d/m/Y'),
                ];
            });

        return Inertia::render('Accounts/Index', [
            'accounts' => $accounts,
            // Vue plateforme multi-organisations : pas de filtrage par organization_id,
            // un nom de rôle en collision entre deux organisations retient le dernier label vu
            // (cas rare, affichage seulement — cf. UserController::indexProps pour l'équivalent
            // org-scopé, sans collision possible).
            'role_labels' => Role::pluck('label', 'name'),
        ]);
    }

    public function toggleActive(User $user): RedirectResponse
    {
        abort_if(! auth()->user()->isSuperAdmin(), 403);
        abort_if($user->id === auth()->id(), 403, 'Vous ne pouvez pas bloquer votre propre compte.');

        $user->update(['is_active' => ! $user->is_active]);

        $action = $user->is_active ? 'débloqué' : 'bloqué';

        return back()->with('success', "{$user->name} a été {$action}.");
    }
}
