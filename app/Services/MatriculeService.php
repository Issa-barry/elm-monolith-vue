<?php

namespace App\Services;

use App\Http\Controllers\UserController;
use App\Models\Employe;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class MatriculeService
{
    /**
     * Generate a random unique 6-digit matricule for a given model table + organization.
     * Retries on collision (up to 100 attempts). Uses lockForUpdate for concurrency safety.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function generate(string $organizationId, string $modelClass): string
    {
        return DB::transaction(function () use ($organizationId, $modelClass) {
            for ($i = 0; $i < 100; $i++) {
                $candidate = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                $taken = $modelClass::where('organization_id', $organizationId)
                    ->where('matricule', $candidate)
                    ->lockForUpdate()
                    ->exists();

                if (! $taken) {
                    return $candidate;
                }
            }

            throw new \RuntimeException('Impossible de générer un matricule unique après 100 tentatives.');
        });
    }

    public function generateForOrganization(string $organizationId): string
    {
        return $this->generate($organizationId, User::class);
    }

    public function assignForUser(User $user): void
    {
        if ($user->matricule !== null || $user->organization_id === null) {
            return;
        }

        $user->update(['matricule' => $this->generate($user->organization_id, User::class)]);
    }

    public function assignForEmploye(Employe $employe): void
    {
        if ($employe->matricule !== null || $employe->organization_id === null) {
            return;
        }

        $employe->update(['matricule' => $this->generate($employe->organization_id, Employe::class)]);
    }

    public static function isStaffRole(string $role): bool
    {
        return in_array($role, UserController::STAFF_ROLES, true);
    }
}
