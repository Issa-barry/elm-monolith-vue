<?php

namespace App\Console\Commands;

use App\Enums\DomaineActivite;
use App\Enums\OtpPurpose;
use App\Enums\SiteType;
use App\Mail\InstallEmailVerificationMail;
use App\Services\InstallationService;
use App\Services\OtpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Première initialisation d'une organisation : crée (ou réutilise) l'organisation, le premier
 * compte super_admin, le catalogue de départ (types de produit, catégories adaptées au domaine,
 * options, types de véhicule — systématique, plus un choix) et le premier site — l'installation
 * laisse l'entreprise réellement prête à l'emploi, même parcours qu'en web (InstallWizardController).
 *
 * Simple façade interactive autour de InstallationService — toute la logique métier (org,
 * super_admin, domaine, catalogue, premier site, installed_at, verrou on-premise) vit dans ce
 * service, partagée avec l'assistant web `/install` (InstallWizardController) : les deux chemins
 * produisent exactement le même résultat pour les mêmes réponses.
 *
 * Le mot de passe est choisi directement par la personne qui répond aux prompts (saisie
 * masquée, jamais affichée ni loguée) — pas de mot de passe généré ni de redéfinition forcée
 * à la première connexion.
 */
class InstallApp extends Command
{
    protected $signature = 'app:install';

    protected $description = "Initialise une organisation (et son premier compte super_admin) — première mise en route de l'application";

    public function handle(InstallationService $service, OtpService $otp): int
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
        $email = $this->askEmail($otp, required: ! $service->isSaas());
        $password = $this->askPassword($service);

        $this->newLine();
        $this->line('----------------------------------------');
        $this->line('Site principal');
        $this->line('----------------------------------------');
        $this->newLine();

        $site = $this->askSite($domaine);

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
                site: $site,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $premierSite = $org->sites()->first();

        $this->line('✓ Entreprise prête : '.$org->name);
        $this->line('✓ Rôles et permissions initialisés');
        $this->line('✓ Super Admin créé');
        $this->line('✓ Domaine d\'activité : '.$domaine->label());
        $this->line('✓ Catalogue par défaut créé (types de produit, catégories, options, types de véhicule)');
        $this->line('✓ Site principal créé : '.($premierSite?->nom ?? '—'));

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
     * Type/ville/quartier du premier site — volontairement minimal (cf. InstallationService::
     * creerSite() pour le nom généré automatiquement et le téléphone/pays hérités du Super Admin).
     * Les types suggérés par le domaine (cf. DomaineActivite::siteTypes()) sont mis en avant en
     * tête de liste (et présélectionnés), mais tous les types restent choisissables — même
     * comportement que "Voir tous les types" côté wizard web (Install/Wizard.vue).
     *
     * @return array{type: string, ville: string, quartier: string}
     */
    private function askSite(DomaineActivite $domaine): array
    {
        $suggeres = $domaine->siteTypes();
        $autres = array_filter(SiteType::cases(), fn (SiteType $t) => ! in_array($t, $suggeres, true));
        $tous = [...$suggeres, ...$autres];

        $labels = array_map(fn (SiteType $t) => $t->label(), $tous);
        $choix = $this->choice('Type de site', $labels, 0);

        $type = null;
        foreach ($tous as $t) {
            if ($t->label() === $choix) {
                $type = $t;
                break;
            }
        }
        // Inatteignable en pratique ($this->choice ne renvoie qu'une des valeurs proposées),
        // filet de sécurité seulement.
        $type ??= SiteType::AUTRE;

        $ville = null;
        while (! $ville) {
            $ville = $this->ask('Ville');
            if (! $ville) {
                $this->error('La ville est obligatoire.');
            }
        }

        $quartier = null;
        while (! $quartier) {
            $quartier = $this->ask('Quartier');
            if (! $quartier) {
                $this->error('Le quartier est obligatoire.');
            }
        }

        return ['type' => $type->value, 'ville' => $ville, 'quartier' => $quartier];
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
     * En on_premise, l'email devient obligatoire (boucle tant qu'il est vide) ; en saas il reste
     * facultatif — même règle que l'assistant web (cf. InstallWizardController::store()), dérivée
     * de InstallationService::isSaas(), jamais une interprétation propre à la CLI. Dans les deux
     * cas, s'il est renseigné, un code est envoyé et doit être saisi correctement avant de
     * poursuivre (cf. InstallWizardController::verifyEmailCode(), EMAIL_OTP_CONTEXT), pour que CLI
     * et web ne puissent jamais diverger sur "email saisi ≠ email vérifié".
     */
    private function askEmail(OtpService $otp, bool $required): ?string
    {
        $email = null;
        while ($email === null) {
            $email = $this->ask($required ? 'Email' : 'Email (facultatif)') ?: null;
            if ($email === null) {
                if (! $required) {
                    return null;
                }
                $this->error("L'adresse email est obligatoire pour cette installation.");
            }
        }

        $this->sendEmailCode($otp, $email);

        while (true) {
            $saisie = $this->ask('Code reçu par email (6 chiffres)') ?? '';

            if ($otp->tooManyAttempts($email, OtpPurpose::EMAIL_VERIFICATION, InstallationService::EMAIL_OTP_CONTEXT)) {
                $this->error('Trop de tentatives — envoi d\'un nouveau code.');
                $this->sendEmailCode($otp, $email);

                continue;
            }

            if (! $otp->hasActiveCode($email, OtpPurpose::EMAIL_VERIFICATION, InstallationService::EMAIL_OTP_CONTEXT)) {
                $this->error('Ce code a expiré — envoi d\'un nouveau code.');
                $this->sendEmailCode($otp, $email);

                continue;
            }

            if (! $otp->verify($email, $saisie, OtpPurpose::EMAIL_VERIFICATION, InstallationService::EMAIL_OTP_CONTEXT)) {
                $this->error('Code incorrect.');

                continue;
            }

            $otp->markVerified($email, OtpPurpose::EMAIL_VERIFICATION, InstallationService::EMAIL_OTP_CONTEXT);
            $this->line('✓ Email vérifié');

            return $email;
        }
    }

    private function sendEmailCode(OtpService $otp, string $email): void
    {
        $code = $otp->generate($email, OtpPurpose::EMAIL_VERIFICATION, InstallationService::EMAIL_OTP_CONTEXT);
        Mail::to($email)->send(new InstallEmailVerificationMail($code));
        $this->line("✓ Un code de vérification a été envoyé à {$email}.");
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
