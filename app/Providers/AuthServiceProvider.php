<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Livreur;
use App\Models\Prestataire;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Policies\LivreurPolicy;
use App\Policies\PrestatairePolicy;
use App\Policies\ProduitPolicy;
use App\Policies\ProprietairePolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Client::class       => ClientPolicy::class,
        Prestataire::class  => PrestatairePolicy::class,
        Livreur::class      => LivreurPolicy::class,
        Proprietaire::class => ProprietairePolicy::class,
        Produit::class      => ProduitPolicy::class,
        User::class         => UserPolicy::class,
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
    }
}
