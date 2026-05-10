<?php

namespace App\Services;

use App\Http\Controllers\UserController;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MatriculeService
{
    /**
     * Generate a random unique 6-digit matricule for an organization.
     * Retries on collision (up to 100 attempts). Uses lockForUpdate to
     * prevent concurrent generation of the same value.
     */
    public function generateForOrganization(string $organizationId): string
    {
        return DB::transaction(function () use ($organizationId) {
            for ($i = 0; $i < 100; $i++) {
                $candidate = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                $taken = User::where('organization_id', $organizationId)
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

    /**
     * Assign a matricule to a user if they don't already have one and belong to an org.
     */
    public function assignForUser(User $user): void
    {
        if ($user->matricule !== null || $user->organization_id === null) {
            return;
        }

        $matricule = $this->generateForOrganization($user->organization_id);
        $user->update(['matricule' => $matricule]);
    }

    public static function isStaffRole(string $role): bool
    {
        return in_array($role, UserController::STAFF_ROLES, true);
    }
}
