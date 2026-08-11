<?php

namespace App\Console\Commands;

use App\Services\InstallationService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Première initialisation d'une organisation : crée (ou réutilise) l'organisation, le premier
 * compte super_admin, et optionnellement un catalogue de départ (catégories, options, types de
 * véhicule).
 *
 * Simple façade interactive autour de InstallationService — toute la logique métier (org,
 * super_admin, catalogue, installed_at) vit dans ce service, partagée avec l'assistant web
 * `/install` (InstallWizardController) : les deux chemins produisent exactement le même
 * résultat pour les mêmes réponses.
 *
 * Le mot de passe est choisi directement par la personne qui répond aux prompts (saisie
 * masquée, jamais affichée ni loguée) — pas de mot de passe généré ni de redéfinition forcée
 * à la première connexion.
 */
class InstallApp extends Command
{
    protected $signature = 'app:install';

    protected $description = "Initialise une organisation (et son premier compte super_admin) — première mise en route de l'application";

    public function handle(InstallationService $service): int
    {
        $this->line('========================================');
        $this->line(' Installation de l\'application');
        $this->line('========================================');
        $this->newLine();

        $nomEntreprise = $this->ask("Nom de l'entreprise");
        if (! $nomEntreprise) {
            $this->error("Le nom de l'entreprise est obligatoire.");

            return self::FAILURE;
        }

        if ($service->hasSuperAdmin($service->resolveOrganization($nomEntreprise))) {
            $this->error('Cette organisation a déjà un compte super_admin — installation déjà faite.');
            $this->line("Pour ajouter d'autres comptes, utilisez le flux d'invitation de l'application.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('----------------------------------------');
        $this->line('Super Administrateur');
        $this->line('----------------------------------------');
        $this->newLine();

        $prenom = $this->ask('Prénom');
        $nom = $this->ask('Nom');
        $telephone = $this->askTelephone($service);
        $email = $this->ask('Email (facultatif)') ?: null;
        [$password, $confirmation] = $this->askPassword($service);

        $categories = $this->confirm('Créer les catégories prédéfinies ?', true);
        $options = $this->confirm('Installer la bibliothèque d\'options prédéfinies ?', true);
        $typesVehicule = $this->confirm('Créer les types de véhicule prédéfinis (Tricycle, Minibus, Camionnette, Camion, Remorque) ?', true);

        $this->newLine();
        $this->info('Création...');

        try {
            $org = $service->install(
                organisation: ['nom' => $nomEntreprise],
                admin: [
                    'prenom' => $prenom,
                    'nom' => $nom,
                    'telephone' => $telephone,
                    'email' => $email,
                    'password' => $password,
                    'password_confirmation' => $confirmation,
                ],
                catalogue: ['categories' => $categories, 'options' => $options, 'types_vehicule' => $typesVehicule],
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->line('✓ Entreprise prête : '.$org->name);
        $this->line('✓ Rôles et permissions initialisés');
        $this->line('✓ Super Admin créé');
        if ($categories) {
            $this->line('✓ Catégories créées');
        }
        if ($options) {
            $this->line('✓ Options créées');
        }
        if ($typesVehicule) {
            $this->line('✓ Types de véhicule créés');
        }

        $this->newLine();
        $this->line('========================================');
        $this->line(' Installation terminée');
        $this->line('========================================');
        $this->newLine();
        $this->warn("Pour des raisons de sécurité, le mot de passe saisi n'est jamais affiché ni conservé en clair.");

        return self::SUCCESS;
    }

    /**
     * Boucle jusqu'à obtenir un numéro international valide, résolu via PhoneCountryInfo
     * (giggsey/libphonenumber-for-php) — jamais de sélection manuelle de pays/indicatif.
     */
    private function askTelephone(InstallationService $service): string
    {
        while (true) {
            $saisie = $this->ask('Téléphone (format international, ex: +224622000000)');
            $info = $saisie ? $service->resolveTelephone($saisie) : null;

            if ($info === null) {
                $this->error('Numéro invalide ou pays non déterminable. Utilisez le format international (ex: +224622000000, +33612345678).');

                continue;
            }

            $this->newLine();
            $this->line('✓ Numéro valide');
            $this->line("✓ Pays détecté : {$info['pays']}");
            $this->line("✓ Indicatif : {$info['indicatif']}");
            $this->line('✓ Devise : '.($info['devise'] ?? 'non déterminée'));
            $this->line('✓ Fuseau horaire : '.($info['fuseau'] ?? 'non déterminé'));
            $this->newLine();

            return $saisie;
        }
    }

    /**
     * Saisie masquée (jamais affichée, jamais loguée) + confirmation — cf. Password::defaults()
     * dans AppServiceProvider pour les règles de complexité (min 8, majuscule+minuscule, symbole).
     *
     * @return array{0: string, 1: string}
     */
    private function askPassword(InstallationService $service): array
    {
        while (true) {
            $password = $this->secret('Mot de passe (min. 8 caractères, majuscule + minuscule + symbole)');
            $confirmation = $this->secret('Confirmer le mot de passe');

            try {
                $service->validatePassword($password ?? '', $confirmation ?? '');
            } catch (ValidationException $e) {
                foreach ($e->errors()['password'] ?? [] as $message) {
                    $this->error($message);
                }

                continue;
            }

            return [$password, $confirmation];
        }
    }
}
