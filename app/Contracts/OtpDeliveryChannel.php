<?php

namespace App\Contracts;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;

/**
 * Contrat de transport d'un code OTP — une implémentation par CANAL métier
 * (`EmailOtpChannel` aujourd'hui ; `SmsOtpChannel`/`WhatsAppOtpChannel`
 * demain), jamais une implémentation par FOURNISSEUR. Un fournisseur SMS
 * (NimbaSMS, LengoSMS, Twilio...) n'implémente JAMAIS ce contrat directement
 * — il implémente `App\Contracts\SmsGateway`, injecté dans `SmsOtpChannel`
 * (qui, lui, implémente ce contrat-ci). Mélanger les deux niveaux recommence
 * à confondre canal et prestataire dès le second fournisseur ajouté (cf.
 * rapport du 27/08/2026). Changer de fournisseur = changer la liaison
 * `SmsGateway` dans le conteneur, sans jamais modifier `OtpService`, les
 * contrôleurs, ni la logique d'authentification.
 *
 * Une implémentation NE DOIT JAMAIS décider si une identité doit être
 * considérée vérifiée — cf. `UserAuthIdentity::markVerifiedVia()`, seul point
 * qui applique cette règle, à l'endroit métier précis où c'est pertinent
 * (jamais automatiquement au moment où un code est simplement transporté).
 *
 * Une implémentation qui appelle un fournisseur distant (SMS/WhatsApp) doit
 * être dispatchée depuis un Job `ShouldQueue` par l'appelant (même pattern
 * que `NotifierLivreursCommandeVenteJob`/`ExpoPushNotificationService`) — ne
 * jamais bloquer une requête HTTP utilisateur sur un appel réseau externe.
 * `EmailOtpChannel` fait exception : il reste synchrone, comme le reste de
 * l'envoi d'email OTP déjà en place dans ce projet (mots de passe oubliés,
 * invitations), un code à durée de vie courte devant arriver au plus vite.
 */
interface OtpDeliveryChannel
{
    public function channel(): OtpChannel;

    /**
     * Le canal peut-il RÉELLEMENT transporter un code en ce moment — au-delà
     * du simple fait d'être déclaré dans `config('otp.channels')` ? Permet à
     * `OtpChannelResolver::firstAvailableFor()` de sauter un canal dont le
     * fournisseur sous-jacent n'a pas ses identifiants renseignés (ex: SMS
     * déclaré mais `NimbaSmsGateway::isConfigured()` faux) plutôt que de le
     * choisir puis échouer silencieusement à l'envoi (cf. audit du
     * 31/08/2026, intégration Nimba SMS). `EmailOtpChannel` retourne toujours
     * `true` — l'email n'a pas de configuration séparée à vérifier ici ; un
     * échec SMTP reste une panne opérationnelle, jamais un défaut de
     * configuration à anticiper (il continue de remonter en erreur, comme
     * avant ce correctif).
     */
    public function isAvailable(): bool;

    /**
     * Envoie le code au destinataire donné — un email pour `EmailOtpChannel`,
     * un numéro E.164 pour un futur canal SMS/WhatsApp. Ne doit jamais changer
     * la logique de génération/validation du code : seul le transport varie
     * d'un canal à l'autre (cf. rapport, point 11 — le même challenge doit
     * pouvoir être retransporté sur un autre canal en cas de fallback, jamais
     * régénéré).
     */
    public function send(string $destination, string $code, OtpPurpose $purpose): void;
}
