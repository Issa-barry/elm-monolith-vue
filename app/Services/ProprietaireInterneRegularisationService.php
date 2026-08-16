<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;

/**
 * Régularise les organisations sans propriétaire interne configuré (cf.
 * Organization::proprietaire_interne_id) — utilisé une fois par la migration
 * 2026_08_16_130000_add_proprietaire_interne_id_to_organizations_table lors du
 * déploiement, et réutilisable ensuite (ex: `php artisan tinker`, une commande de
 * rattrapage) pour toute organisation qui échapperait encore à la règle.
 *
 * Ne devine jamais un propriétaire à partir d'une source ambiguë (ex : fichier d'import
 * flotte, nom approximatif) — seules deux sources fiables sont utilisées, dans cet ordre :
 *
 *   1) l'ancien propriétaire "magique" au téléphone +224622602693, s'il existe déjà
 *      (organisations historiques type "elm", cf. ProprietairesSeeder) ;
 *   2) à défaut, le compte super_admin de l'organisation, s'il en existe exactement un —
 *      son identité (nom/prénom/téléphone) a été saisie une fois à l'installation
 *      (cf. InstallationService::install()), donc fiable pour ce rôle sans re-questionner
 *      personne ; réutilise le Proprietaire existant à ce téléphone s'il y en a un, sinon
 *      en crée un et le rattache au compte User (user_id) pour tracer qu'il s'agit de la
 *      même personne.
 *
 * Une organisation qui ne remplit aucun des deux cas (plusieurs super_admin, aucun,
 * organisation sans utilisateur) reste sans propriétaire interne configuré : elle devra
 * être régularisée explicitement (page Propriétaires → "Définir comme propriétaire
 * interne"), jamais associée à une personne devinée arbitrairement.
 */
class ProprietaireInterneRegularisationService
{
    private const TELEPHONE_MAGIQUE_HISTORIQUE = '+224622602693';

    /**
     * @return int nombre d'organisations régularisées
     */
    public function regulariserToutes(): int
    {
        $count = 0;

        Organization::whereNull('proprietaire_interne_id')->get(['id'])->each(function (Organization $org) use (&$count) {
            if ($this->regulariser($org)) {
                $count++;
            }
        });

        return $count;
    }

    /**
     * @return bool true si l'organisation a été régularisée (ou l'était déjà)
     */
    public function regulariser(Organization $org): bool
    {
        if ($org->proprietaire_interne_id) {
            return true;
        }

        $proprietaireId = Proprietaire::where('organization_id', $org->id)
            ->where('telephone', self::TELEPHONE_MAGIQUE_HISTORIQUE)
            ->whereNull('deleted_at')
            ->value('id');

        if (! $proprietaireId) {
            $proprietaireId = $this->resoudreDepuisSuperAdminUnique($org);
        }

        if (! $proprietaireId) {
            return false;
        }

        $org->forceFill(['proprietaire_interne_id' => $proprietaireId])->save();

        return true;
    }

    private function resoudreDepuisSuperAdminUnique(Organization $org): ?string
    {
        // whereHas plutôt que le scope role() de Spatie : ne doit jamais planter si le rôle
        // "super_admin" n'existe pas encore en base (organisation créée avant tout seeding de
        // rôles) — dans ce cas, simplement aucun résultat, pas une exception. Pas de
        // whereNull('deleted_at') : users n'a pas de soft delete (cf. migration de la table).
        $superAdmins = User::where('organization_id', $org->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin')->where('guard_name', 'web'))
            ->get();

        if ($superAdmins->count() !== 1) {
            return null;
        }

        $admin = $superAdmins->first();

        $proprietaireId = Proprietaire::where('organization_id', $org->id)
            ->where('telephone', $admin->telephone)
            ->whereNull('deleted_at')
            ->value('id');

        if ($proprietaireId) {
            return $proprietaireId;
        }

        return Proprietaire::create([
            'organization_id' => $org->id,
            'user_id' => $admin->id,
            'nom' => $admin->nom,
            'prenom' => $admin->prenom,
            'telephone' => $admin->telephone,
            'code_pays' => $admin->code_pays,
            'pays' => $admin->pays,
            'code_phone_pays' => $admin->code_phone_pays,
            'is_active' => true,
        ])->id;
    }
}
