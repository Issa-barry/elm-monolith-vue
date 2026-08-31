<?php

namespace App\Services\Otp;

use App\Contracts\OtpDeliveryChannel;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;

/**
 * Résout un `OtpDeliveryChannel` à partir de `config('otp.channels')`
 * (cf. rapport du 27/08/2026) — un changement de fournisseur (NimbaSMS →
 * LengoSMS, ou l'ajout d'un `WhatsAppOtpChannel`) se fait uniquement en
 * modifiant cette configuration ou la liaison `App\Contracts\SmsGateway`,
 * jamais `OtpService`, les contrôleurs, ni la logique d'authentification.
 */
final class OtpChannelResolver
{
    public function resolve(OtpChannel $channel): OtpDeliveryChannel
    {
        $class = config("otp.channels.{$channel->value}");

        abort_unless(is_string($class), 500, "Canal OTP [{$channel->value}] non configuré (config('otp.channels')).");

        return app($class);
    }

    public function isConfigured(OtpChannel $channel): bool
    {
        return is_string(config("otp.channels.{$channel->value}"));
    }

    /**
     * Premier canal RÉELLEMENT disponible pour ce purpose ET ce destinataire
     * précis, dans l'ordre déclaré par `config('otp.purpose_channels')`.
     *
     * "Disponible" est ici la conjonction de QUATRE conditions — jamais
     * seulement "configuré" (correctif du 27/08/2026, cf. rapport ; complété
     * le 31/08/2026 lors de l'intégration Nimba SMS) :
     *   1. le canal fait partie de la liste souhaitée pour ce purpose ;
     *   2. une implémentation est réellement liée dans `config('otp.channels')` ;
     *   3. `$phone`/`$email` fournissent une coordonnée EXPLOITABLE pour ce
     *      canal précis (cf. `destinationFor()`) — un canal email configuré
     *      globalement mais sans email connu pour CE compte n'est pas
     *      disponible pour lui, et la résolution passe au canal suivant de
     *      la liste plutôt que d'échouer inutilement ;
     *   4. le canal résolu se déclare lui-même réellement disponible
     *      (`OtpDeliveryChannel::isAvailable()`) — ex: SMS déclaré dans
     *      `config('otp.channels')` mais fournisseur (NimbaSmsGateway) sans
     *      identifiants renseignés.
     *
     * `null` si aucun canal de la liste n'est disponible pour ce
     * destinataire — l'appelant doit alors refuser proprement, jamais
     * générer un code qui ne sera livré nulle part.
     *
     * CE QUE CETTE MÉTHODE N'EST PAS : une politique de fallback D'ENVOI.
     * Elle choisit un canal UNE SEULE FOIS, avant toute tentative d'envoi.
     * Si l'envoi échoue réellement (ex: panne fournisseur), ce n'est PAS à
     * cette méthode de retenter automatiquement un autre canal — un tel
     * comportement serait une décision produit explicite à construire à
     * part (cf. rapport, point 11 : un fallback WhatsApp→SMS doit
     * retransporter le MÊME code, jamais en régénérer un, et ne doit jamais
     * être un effet de bord silencieux de cette résolution).
     *
     * Ne gère pas non plus un choix EXPLICITE de canal par l'utilisateur —
     * aucun parcours ne l'expose aujourd'hui. Le jour où ce besoin existe, il
     * passera par une méthode dédiée à cette décision (ex. un futur
     * `resolvePreferred(OtpChannel $requested, ...)` qui vérifie seulement
     * que CE canal précis est disponible), sans modifier la résolution
     * automatique ci-dessous.
     */
    public function firstAvailableFor(OtpPurpose $purpose, ?string $phone, ?string $email): ?OtpDeliveryChannel
    {
        $channels = config("otp.purpose_channels.{$purpose->value}", []);

        return $this->firstAvailableAmong($channels, $phone, $email)['delivery'] ?? null;
    }

    /**
     * Canal de REPLI explicite à utiliser si `$chosen` (le canal retenu par
     * `firstAvailableFor()` pour ce même purpose/destinataire) échoue APRÈS
     * avoir été choisi — ex: SMS jugé "disponible" (identifiants Nimba
     * présents) mais l'appel réel échoue (panne fournisseur, solde
     * insuffisant, timeout...). Ajouté le 31/08/2026 (audit intégration
     * Nimba SMS, point 2) : avant ce correctif, aucun mécanisme ne
     * retransportait réellement le code par un autre canal dans ce cas — le
     * client recevait `channel: "sms"` sans qu'aucun SMS n'arrive jamais.
     *
     * Retourne le premier canal RÉELLEMENT disponible dans la même liste
     * ordonnée `purpose_channels`, après `$chosen` — jamais recalculé
     * ailleurs, jamais un canal qui régénérerait un nouveau code (le MÊME
     * code est retransporté par l'appelant, cf. `OtpFallbackTarget` et
     * `SendSmsOtpJob`). `null` si aucun canal suivant n'est disponible (rien
     * à faire de plus qu'à journaliser l'échec).
     *
     * Reste un calcul explicite, appelé une seule fois par l'appelant AU
     * MOMENT de la résolution primaire (jamais un effet de bord de
     * `firstAvailableFor()` elle-même, cf. son docblock) : c'est la
     * "décision produit explicite à construire à part" que ce docblock
     * annonçait déjà.
     */
    public function fallbackFor(OtpChannel $chosen, OtpPurpose $purpose, ?string $phone, ?string $email): ?OtpFallbackTarget
    {
        $channels = config("otp.purpose_channels.{$purpose->value}", []);
        $afterChosen = array_slice($channels, array_search($chosen->value, $channels, true) + 1);

        $found = $this->firstAvailableAmong($afterChosen, $phone, $email);

        return $found === null ? null : new OtpFallbackTarget($found['channel'], $found['destination']);
    }

    /**
     * @param  list<string>  $channelValues  Valeurs brutes de App\Enums\OtpChannel, dans l'ordre à essayer.
     * @return array{delivery: OtpDeliveryChannel, channel: OtpChannel, destination: string}|null
     */
    private function firstAvailableAmong(array $channelValues, ?string $phone, ?string $email): ?array
    {
        foreach ($channelValues as $channelValue) {
            $channel = OtpChannel::tryFrom($channelValue);

            if ($channel === null || ! $this->isConfigured($channel)) {
                continue;
            }

            $destination = $this->destinationFor($channel, $phone, $email);
            if ($destination === null) {
                continue;
            }

            $delivery = $this->resolve($channel);

            // Ajouté lors de l'intégration Nimba SMS (audit du 31/08/2026) :
            // "configuré" (classe déclarée dans config('otp.channels')) ne
            // suffit plus — le canal doit aussi se déclarer RÉELLEMENT
            // disponible (ex: SmsOtpChannel::isAvailable() délègue à
            // NimbaSmsGateway::isConfigured()), sinon on continue vers le
            // canal suivant plutôt que de choisir un canal qui échouera
            // silencieusement à l'envoi.
            if (! $delivery->isAvailable()) {
                continue;
            }

            return ['delivery' => $delivery, 'channel' => $channel, 'destination' => $destination];
        }

        return null;
    }

    /**
     * Destination à utiliser pour ce canal — un email pour `OtpChannel::EMAIL`
     * (seulement s'il est réellement renseigné, non vide), le numéro
     * lui-même pour SMS/WhatsApp. `null` si l'information nécessaire n'est
     * pas disponible (ex: canal email choisi mais aucun email connu pour ce
     * compte) — c'est exactement ce que `firstAvailableFor()` utilise pour
     * juger un canal "disponible" ou non pour un destinataire donné.
     */
    public function destinationFor(OtpChannel $channel, ?string $phone, ?string $email): ?string
    {
        return match ($channel) {
            OtpChannel::EMAIL => filled($email) ? $email : null,
            OtpChannel::SMS, OtpChannel::WHATSAPP => filled($phone) ? $phone : null,
        };
    }
}
