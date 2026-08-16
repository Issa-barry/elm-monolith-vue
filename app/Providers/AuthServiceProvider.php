<?php

namespace App\Providers;

use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommandeAchat;
use App\Models\CommandeVente;
use App\Models\EquipeLivraison;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\OptionCatalogue;
use App\Models\Packing;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\PaiePeriode;
use App\Models\Prestataire;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Models\PropositionVehicule;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\UserInvitation;
use App\Models\Vehicule;
use App\Policies\CategoriePolicy;
use App\Policies\ClientPolicy;
use App\Policies\CommandeAchatPolicy;
use App\Policies\CommandeVentePolicy;
use App\Policies\EquipeLivraisonPolicy;
use App\Policies\FournisseurPolicy;
use App\Policies\LivreurPolicy;
use App\Policies\OptionCataloguePolicy;
use App\Policies\PackingPolicy;
use App\Policies\PaiementFichePolicy;
use App\Policies\PaiementPeriodePolicy;
use App\Policies\PaiePolicy;
use App\Policies\PrestatairePolicy;
use App\Policies\ProduitPolicy;
use App\Policies\ProduitTypePolicy;
use App\Policies\PropositionVehiculePolicy;
use App\Policies\ProprietairePolicy;
use App\Policies\SitePolicy;
use App\Policies\UserInvitationPolicy;
use App\Policies\UserPolicy;
use App\Policies\VehiculePolicy;
use App\Auth\UserIdentityProvider;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Categorie::class => CategoriePolicy::class,
        Client::class => ClientPolicy::class,
        Prestataire::class => PrestatairePolicy::class,
        Fournisseur::class => FournisseurPolicy::class,
        Livreur::class => LivreurPolicy::class,
        Proprietaire::class => ProprietairePolicy::class,
        Produit::class => ProduitPolicy::class,
        ProduitType::class => ProduitTypePolicy::class,
        Packing::class => PackingPolicy::class,
        User::class => UserPolicy::class,
        Vehicule::class => VehiculePolicy::class,
        EquipeLivraison::class => EquipeLivraisonPolicy::class,
        Site::class => SitePolicy::class,
        UserInvitation::class => UserInvitationPolicy::class,
        CommandeVente::class => CommandeVentePolicy::class,
        CommandeAchat::class => CommandeAchatPolicy::class,
        PropositionVehicule::class => PropositionVehiculePolicy::class,
        PaiePeriode::class => PaiePolicy::class,
        PaiementPeriode::class => PaiementPeriodePolicy::class,
        PaiementFiche::class => PaiementFichePolicy::class,
        OptionCatalogue::class => OptionCataloguePolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // super_admin bypass propre — retourner true court-circuite la policy
        // sans jamais retourner false, ce qui préserve les deny explicites
        Gate::before(function (User $user, string $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }
        });

        // Résout par email/telephone via user_auth_identities — ces colonnes n'existent
        // plus sur `users` depuis PERSONNE + USERS (cf. UserIdentityProvider).
        Auth::provider('user_identity', fn ($app, array $config) => new UserIdentityProvider(
            $app['hash'],
            $config['model']
        ));
    }
}
