<?php

namespace Database\Seeders;

use App\Enums\PrestataireType;
use App\Models\EntrepriseTierce;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Prestataire;
use Illuminate\Database\Seeder;

/**
 * Prestataire "Fello-Consulting" (société de conseil, contact Abdoulaye DIALLO) — donnée réelle,
 * jamais une démo : contrairement à PrestatairesSeeder/FournisseursSeeder (rattachés à
 * l'organisation fixe "elm", absente en preprod/prod), ce seeder boucle sur TOUTES les
 * organisations existantes et ne crée jamais d'organisation lui-même — sûr à lancer à chaque
 * déploiement (`db:seed --class=FelloConsultingPrestataireSeeder --force`, cf.
 * deploy-hostinger-admin.yml / deploy-hostinger-formation.yml), y compris sur une instance
 * on-premise neuve où `organizations` est encore vide (boucle sans effet tant que /install
 * n'est pas complété, cf. RolesAndPermissionsSeeder). Idempotent : resoudreOuCreer()/
 * firstOrCreate() ne dupliquent jamais l'entité au sein d'une même organisation.
 */
class FelloConsultingPrestataireSeeder extends Seeder
{
    public function run(): void
    {
        Organization::all(['id'])->each(fn (Organization $org) => $this->seedPourOrganisation($org->id));
    }

    private function seedPourOrganisation(string $organizationId): void
    {
        $contact = Personne::resoudreOuCreer($organizationId, [
            'nom' => 'Diallo',
            'prenom' => 'Abdoulaye',
            'telephone' => '+33758855039',
            'email' => 'issabarry67@gmail.com',
            'pays' => 'France',
            'code_pays' => 'FR',
            'code_phone_pays' => '+33',
            'ville' => 'Paris',
        ]);

        $entreprise = EntrepriseTierce::resoudreOuCreer($organizationId, [
            'raison_sociale' => 'Fello-Consulting',
            'telephone' => '+33758855039',
            'email' => 'issabarry67@gmail.com',
            'pays' => 'France',
            'code_pays' => 'FR',
            'code_phone_pays' => '+33',
            'ville' => 'Paris',
            'contact_personne_id' => $contact->id,
        ]);

        Prestataire::firstOrCreate(
            [
                'organization_id' => $organizationId,
                'entreprise_tierce_id' => $entreprise->id,
            ],
            [
                'type' => PrestataireType::CONSULTANT->value,
                'is_active' => true,
            ]
        );
    }
}
