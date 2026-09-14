<?php

namespace App\Support\User;

use App\Models\Site;
use App\Models\User;
use App\Support\Permissions\RoleVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Options de formulaire (rôles/sites/pays) partagées par les contrôleurs `App\Http\Controllers\
 * User\*` — extrait de l'ancien `UserController`.
 */
final class UserFormOptions
{
    public const STAFF_ROLES = ['super_admin', 'admin_entreprise', 'manager', 'commerciale', 'comptable'];

    /**
     * Rôles pouvant être attribués via une invitation. Les rôles admin sont
     * volontairement exclus : ils ne peuvent être attribués qu'après validation
     * du compte, depuis la gestion utilisateur (voir Role\UpdateRoleController).
     */
    public const INVITABLE_ROLES = ['manager', 'commerciale', 'comptable'];

    public const ADMIN_ROLES = ['super_admin', 'admin_entreprise'];

    public const PAYS = [
        'GN' => ['Guinée',               '+224'],
        'GW' => ['Guinée-Bissau',        '+245'],
        'SN' => ['Sénégal',              '+221'],
        'ML' => ['Mali',                 '+223'],
        'CI' => ["Côte d'Ivoire",        '+225'],
        'LR' => ['Liberia',              '+231'],
        'SL' => ['Sierra Leone',         '+232'],
        'FR' => ['France',               '+33'],
        'CN' => ['Chine',                '+86'],
        'AE' => ['Émirats arabes unis',  '+971'],
        'IN' => ['Inde',                 '+91'],
    ];

    /**
     * Rôles STAFF assignables dans une organisation — système (organization_id null) ∪ propres
     * à cette organisation, jamais ceux d'une autre organisation ni les rôles externes
     * (client/proprietaire/livreur, gérés par un autre flux — cf. User::EXTERNAL_ROLES).
     * `super_admin` n'apparaît que si l'acteur l'est déjà lui-même (jamais un mapping
     * automatique, jamais une élévation de privilège via cet écran, cf.
     * User\UserPrivilegeGuard::assertNoPrivilegeEscalation()). Remplace l'ancienne liste figée
     * STAFF_ROLES (5 noms techniques) : un rôle personnalisé créé via Role\StoreRoleController
     * est désormais proposable ici comme n'importe quel autre rôle — seule et unique méthode de
     * scoping des deux écrans qui en avaient chacun une copie légèrement différente
     * (Users/Create-Edit et validation de compte), factorisée ici pour ne plus jamais diverger.
     */
    public static function assignableStaffRoles(?string $orgId, bool $actorIsSuperAdmin): Collection
    {
        return RoleVisibility::query($orgId)
            ->whereNotIn('name', User::EXTERNAL_ROLES)
            ->when(! $actorIsSuperAdmin, fn ($q) => $q->where('name', '!=', 'super_admin'))
            ->orderBy('label')
            ->get(['id', 'name', 'label']);
    }

    /** Users/Create-Edit attend le NOM technique (assignRole()/syncRoles() par nom). */
    public static function getRoleOptions(?string $orgId, bool $actorIsSuperAdmin): Collection
    {
        return self::assignableStaffRoles($orgId, $actorIsSuperAdmin)
            ->map(fn (Role $r) => ['value' => $r->name, 'label' => $r->label ?? $r->name])
            ->values();
    }

    public static function getSiteOptions(string $orgId): Collection
    {
        return Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom', 'code'])
            ->map(fn ($s) => ['value' => $s->id, 'label' => "{$s->nom} ({$s->code})"]);
    }

    /** Écran de VALIDATION de compte : attend l'ID (AccountValidationService résout par id). */
    public static function validationRoleOptions(?string $orgId, bool $actorIsSuperAdmin): Collection
    {
        return self::assignableStaffRoles($orgId, $actorIsSuperAdmin)
            ->map(fn (Role $r) => ['value' => $r->id, 'label' => $r->label ?? $r->name])
            ->values();
    }

    /**
     * Règle de validation du champ `role` de Users/Create-Edit — miroir exact du scoping de
     * assignableStaffRoles() (même organisation ∪ système, jamais un rôle externe) : un rôle
     * posté directement en HTTP (hors du <select>) doit être rejeté par les mêmes règles que
     * celles qui décident de ce qui est proposé, jamais une liste séparée qui pourrait diverger.
     */
    public static function assignableRoleRule(?string $orgId)
    {
        return Rule::exists('roles', 'name')->where(function ($query) use ($orgId) {
            $query->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $orgId))
                ->whereNotIn('name', User::EXTERNAL_ROLES);
        });
    }

    public static function resolvePays(?string $codePays): array
    {
        if ($codePays && isset(self::PAYS[$codePays])) {
            [$pays, $codePhonePays] = self::PAYS[$codePays];

            return ['pays' => $pays, 'code_phone_pays' => $codePhonePays];
        }

        return ['pays' => null, 'code_phone_pays' => null];
    }

    public static function buildFullTelephone(Request $request): void
    {
        $codePays = $request->input('code_pays');
        $local = (string) $request->input('telephone', '');

        if ($codePays && isset(self::PAYS[$codePays]) && $local !== '') {
            [, $dial] = self::PAYS[$codePays];
            $dialDigits = preg_replace('/\D+/', '', $dial) ?? '';
            $localDigits = preg_replace('/\D+/', '', $local) ?? '';
            $localDigits = preg_replace('/^0/', '', $localDigits);
            $request->merge(['telephone' => '+'.$dialDigits.$localDigits]);
        }
    }
}
