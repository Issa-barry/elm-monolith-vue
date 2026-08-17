<?php

namespace App\Console\Commands;

use App\Enums\DomaineActivite;
use App\Services\InstallationService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Première initialisation d'une organisation : crée (ou réutilise) l'organisation, le premier
 * compte super_admin et le catalogue de départ (types de produit, catégories adaptées au
 * domaine, options, types de véhicule — systématique, plus un choix). Le premier site n'est plus
 * créé ici : il se configure à la première connexion (onboarding, cf.
 * OnboardingSiteController), même parcours qu'en web.
 *
 * Simple façade interactive autour de InstallationService — toute la logique métier (org,
 * super_admin, domaine, catalogue, installed_at, verrou on-premise) vit dans ce service, partagée
 * avec l'assistant web `/install` (InstallWizardController) : les deux chemins produisent
 * exactement le même résultat pour les mêmes réponses.
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

        if ($service->hasSuperAdminForName($nomEntreprise)) {
            $this->error('Cette organisation a déjà un compte super_admin — installation déjà faite.');
            $this->line("Pour ajouter d'autres comptes, utilisez le flux d'invitation de l'application.");

            return self::FAILURE;
        }

        $this->newLine();
        $domaine = $this->askDomaine();

        $this->newLine();
        $this->line('----------------------------------------');
        $this->line('Super Administrateur');
        $this->line('----------------------------------------');
        $this->newLine();

        $prenom = $this->ask('Prénom');
        $nom = $this->ask('Nom');
        $telephone = $this->askTelephone($service);
        $email = $this->ask('Email (facultatif)') ?: null;
        $password = $this->askPassword($service);

        $this->newLine();
        $this->info('Création...');

        try {
            $org = $service->install(
                organisation: ['nom' => $nomEntreprise, 'domaine' => $domaine->value],
                admin: [
                    'prenom' => $prenom,
                    'nom' => $nom,
                    'telephone' => $telephone,
                    'email' => $email,
                    'password' => $password,
                ],
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
        $this->line('✓ Domaine d\'activité : '.$domaine->label());
        $this->line('✓ Catalogue par défaut créé (types de produit, catégories, options, types de véhicule)');
        $this->line('  Le premier site se configure à la première connexion.');

        $this->newLine();
        $this->line('========================================');
        $this->line(' Installation terminée');
        $this->line('========================================');
        $this->newLine();
        $this->warn("Pour des raisons de sécurité, le mot de passe saisi n'est jamais affiché ni conservé en clair.");

        return self::SUCCESS;
    }

    /**
     * Menu à choix fermé (pas de saisie libre) — les 5 domaines sont fixes, cf.
     * App\Enums\DomaineActivite.
     */
    private function askDomaine(): DomaineActivite
    {
        $labels = array_map(fn (DomaineActivite $d) => $d->label(), DomaineActivite::cases());
        $choix = $this->choice("Domaine d'activité de l'entreprise", $labels, 0);

        foreach (DomaineActivite::cases() as $domaine) {
            if ($domaine->label() === $choix) {
                return $domaine;
            }
        }

        // Inatteignable en pratique ($this->choice ne renvoie qu'une des valeurs proposées),
        // filet de sécurité seulement.
        return DomaineActivite::AUTRE;
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
     * Saisie masquée (jamais affichée, jamais loguée), une seule fois — pas de confirmation, pour
     * une installation plus rapide. Cf. Password::defaults() dans AppServiceProvider pour les
     * règles de complexité (min 8, majuscule+minuscule, symbole).
     */
    private function askPassword(InstallationService $service): string
    {
        while (true) {
            $password = $this->secret('Mot de passe (min. 8 caractères, majuscule + minuscule + symbole)') ?? '';

            try {
                $service->validatePassword($password, $password);
            } catch (ValidationException $e) {
                foreach ($e->errors()['password'] ?? [] as $message) {
                    $this->error($message);
                }

                continue;
            }

            return $password;
        }
    }
}
