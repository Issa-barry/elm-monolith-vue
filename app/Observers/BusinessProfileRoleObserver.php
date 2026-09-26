<?php

namespace App\Observers;

use App\Models\Client;
use App\Models\Livreur;
use App\Models\Proprietaire;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Garantit structurellement que Client/Proprietaire/Livreur.user_id et le rôle
 * Spatie correspondant ne peuvent plus diverger, quel que soit le code qui pose
 * ce rattachement — actuel ou futur. Élimine la classe de bug corrigée le
 * 26/08/2026 (LoginController et BackofficeLoginController::
 * lierCompteParTelephone() posaient user_id sans jamais attribuer le rôle) en la
 * rendant impossible à réintroduire : plus aucun call site n'a besoin de se
 * souvenir d'appeler assignRole() lui-même quand il lie un profil à un compte.
 *
 * Les flux d'inscription (CreateNewUser, RegistrationService,
 * LivreurRegistrationController) gardent volontairement leurs propres
 * assignRole() explicites : cet observer les rend redondants (donc sans risque,
 * assignRole() sur un rôle déjà présent est un no-op) mais ne les remplace pas —
 * les toucher n'apporterait rien et risquerait une régression sur un code déjà
 * correct et déjà testé.
 *
 * IMPORTANT — ne se déclenche que sur save()/update() d'une INSTANCE de modèle :
 * un update() de masse via le query builder (Model::where(...)->update([...]))
 * ne déclenche jamais les events Eloquent. Toujours charger l'instance avant de
 * poser user_id si on veut bénéficier de cette garantie.
 *
 * Ne gère volontairement que le sens "liaison" (user_id passe de null à non-null)
 * — aucun flux applicatif ne "délie" aujourd'hui un profil déjà rattaché ; le jour
 * où une fonctionnalité de dé-rattachement existe, décider explicitement à ce
 * moment-là si le rôle doit être retiré (un compte peut légitimement garder un
 * historique de rôle après un dé-rattachement, à trancher métier par métier).
 *
 * PIÈGE CORRIGÉ (05/09/2026) : wasChanged('user_id') est FAUX quand user_id est
 * déjà présent dans les attributs au moment du create() (rien à comparer à un
 * "original" qui n'existait pas encore) — seul un update() ultérieur sur une
 * instance existante déclenche wasChanged(). InstallationService::install() crée
 * justement le Proprietaire interne avec user_id posé dès le create() : le rôle
 * n'était donc jamais attribué au super_admin qui installe l'application, malgré
 * cet observer déjà en place. wasRecentlyCreated couvre ce cas précis, en plus de
 * wasChanged() qui reste nécessaire pour le rattachement a posteriori (le cas déjà
 * couvert depuis le 26/08/2026, cf. RolesCoherenceCommand pour rattraper les
 * comptes existants déjà affectés).
 */
class BusinessProfileRoleObserver
{
    /** @var array<class-string<Model>, string> */
    private const ROLE_BY_MODEL = [
        Client::class => 'client',
        Proprietaire::class => 'proprietaire',
        Livreur::class => 'livreur',
    ];

    public function saved(Model $profile): void
    {
        if ($profile->user_id === null) {
            return;
        }

        $justLinked = $profile->wasRecentlyCreated || $profile->wasChanged('user_id');

        if (! $justLinked) {
            return;
        }

        $role = self::ROLE_BY_MODEL[$profile::class] ?? null;

        if ($role === null) {
            return;
        }

        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $profile->user?->assignRole($role);
    }
}
