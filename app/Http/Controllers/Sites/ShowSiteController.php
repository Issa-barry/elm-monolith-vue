<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Http\Controllers\UserController;
use App\Models\Site;
use App\Models\UserInvitation;
use App\Models\Vehicule;
use App\Support\Sites\SiteDataFormatter;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class ShowSiteController extends Controller
{
    public function __invoke(Site $site): Response
    {
        $this->authorize('view', $site);

        $site->load([
            'parent',
            'enfants',
            'users.roles',
            'invitations' => fn ($q) => $q->orderByDesc('created_at'),
            'vehicules' => fn ($q) => $q->orderBy('nom_vehicule'),
        ]);

        $canInvite = auth()->user()->can('invite', $site);

        // Active users assigned to this site
        $membresUsers = $site->users->map(fn ($u) => [
            'id' => $u->id,
            'type' => 'user',
            'invitation_id' => null,
            'nom_complet' => $u->name,
            'email' => $u->email,
            'telephone' => $u->telephone,
            'role' => $u->getRoleNames()->first(),
            'statut' => $u->isPendingValidation() ? 'pending_validation' : ($u->is_active ? 'actif' : 'inactif'),
            'statut_label' => $u->isPendingValidation() ? 'En attente de validation' : ($u->is_active ? 'Actif' : 'Inactif'),
            'date' => $u->pivot->created_at?->format('d/m/Y'),
            'can_resend' => false,
            'can_revoke' => false,
            'can_delete' => false,
        ])->values();

        // Invitations (pending/expired/revoked — skip accepted since they become users)
        $membresInvitations = $site->invitations
            ->filter(fn (UserInvitation $inv) => ! $inv->isAccepted())
            ->map(fn (UserInvitation $inv) => [
                'id' => null,
                'type' => 'invitation',
                'invitation_id' => $inv->id,
                'nom_complet' => null,
                'email' => $inv->email,
                'telephone' => null,
                'role' => $inv->role,
                'statut' => $inv->statut,
                'statut_label' => $inv->statut_label,
                'date' => $inv->created_at?->format('d/m/Y'),
                'can_resend' => $canInvite && in_array($inv->statut, ['expired', 'revoked'], true),
                'can_revoke' => $canInvite && $inv->statut === 'pending',
                'can_delete' => $canInvite && in_array($inv->statut, ['expired', 'revoked'], true),
            ])->values();

        $membres = $membresUsers->concat($membresInvitations)->values();

        $rolesDisponibles = Role::whereIn('name', UserController::INVITABLE_ROLES)
            ->get(['id', 'name'])
            ->map(fn ($r) => ['value' => $r->name, 'label' => $this->roleLabel($r->name)])
            ->values();

        $vehicules = $site->vehicules->map(fn (Vehicule $v) => [
            'id' => $v->id,
            'nom_vehicule' => $v->nom_vehicule,
            'immatriculation' => $v->immatriculation,
            'type_label' => $v->type_label,
            'is_active' => $v->is_active,
        ])->values()->all();

        $canCreateVehicule = auth()->user()->can('create', Vehicule::class);

        return Inertia::render('Sites/Show', [
            'site' => [
                ...SiteDataFormatter::pour($site),
                'enfants' => $site->enfants->map(fn (Site $e) => [
                    'id' => $e->id,
                    'nom' => $e->nom,
                    'code' => $e->code,
                    'type_label' => $e->type_label,
                    'statut' => $e->statut?->value,
                    'statut_label' => $e->statut_label,
                ]),
            ],
            'membres' => $membres,
            'roles_disponibles' => $rolesDisponibles,
            'can_invite' => $canInvite,
            'vehicules' => $vehicules,
            'can_create_vehicule' => $canCreateVehicule,
        ]);
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'super_admin' => 'Super administrateur',
            'admin_entreprise' => 'Administrateur',
            'manager' => 'Manager',
            'commerciale' => 'Commercial(e)',
            'comptable' => 'Comptable',
            default => $role,
        };
    }
}
