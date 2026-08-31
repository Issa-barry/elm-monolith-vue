<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use App\Exceptions\NimbaSmsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fournisseur SMS Nimba (Guinée) — implémente App\Contracts\SmsGateway,
 * jamais App\Contracts\OtpDeliveryChannel directement (cf. docblock de
 * SmsGateway : ne jamais mélanger canal métier et fournisseur).
 *
 * Transport SMS BRUT uniquement : Laravel (App\Services\OtpService) reste
 * l'unique générateur/validateur des codes OTP — Nimba se contente de relayer
 * un message déjà généré. Endpoint choisi après audit du 31/08/2026 :
 * `POST /v1/messages` (SDK officiels `nimbasms/nimbasms-python`,
 * `nimbasms/nimbasms-php` : `base_uri = https://api.nimbasms.com`,
 * `auth = [service_id, secret_token]`, i.e. HTTP Basic Auth), **jamais**
 * `/v1/verifications` — cet endpoint ferait de Nimba une seconde source de
 * vérité pour la génération/validation des codes, alors que
 * App\Services\OtpService gère déjà tout ça (TTL, cooldown, tentatives
 * max, fallback multi-canal en retransportant le MÊME code) : l'utiliser
 * dupliquerait cette logique dans un système externe qu'on ne contrôle pas.
 *
 * Authentification : Service ID + Secret Token en HTTP Basic Auth construite
 * ici via Http::withBasicAuth() — jamais un en-tête Authorization stocké ou
 * fourni séparément par l'appelant.
 */
class NimbaSmsGateway implements SmsGateway
{
    private const BASE_URL = 'https://api.nimbasms.com';

    /** Timeout HTTP total — un OTP est urgent mais ne doit jamais bloquer indéfiniment une requête/job. */
    private const TIMEOUT_SECONDS = 10;

    public function isConfigured(): bool
    {
        return filled(config('services.nimba_sms.service_id'))
            && filled(config('services.nimba_sms.secret_token'))
            && filled(config('services.nimba_sms.sender_name'));
    }

    /**
     * @param  string  $phoneNumber  Format international déjà normalisé par ELM (ex: "+224620000000").
     *
     * @throws NimbaSmsException Configuration manquante, réponse Nimba non-2xx
     *                           (sender name non validé, solde insuffisant,
     *                           identifiants invalides...), ou erreur réseau/timeout.
     */
    public function send(string $phoneNumber, string $message): void
    {
        if (! $this->isConfigured()) {
            throw new NimbaSmsException(
                'Nimba SMS : configuration manquante (NIMBA_SMS_SERVICE_ID/NIMBA_SMS_SECRET_TOKEN/NIMBA_SMS_SENDER_NAME).'
            );
        }

        try {
            $response = Http::withBasicAuth(
                (string) config('services.nimba_sms.service_id'),
                (string) config('services.nimba_sms.secret_token'),
            )
                ->asForm()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::BASE_URL.'/v1/messages', [
                    // Un seul destinataire par envoi OTP — envoyé comme
                    // valeur scalaire plutôt qu'un tableau indexé `to[0]=`
                    // (encodage `http_build_query` par défaut de Laravel/PHP,
                    // jamais confirmé côté Nimba) : c'est exactement
                    // l'encodage produit par le SDK officiel Python
                    // (`requests`, doseq) pour une liste à un seul élément,
                    // cf. audit du 31/08/2026.
                    'to' => $phoneNumber,
                    'sender_name' => config('services.nimba_sms.sender_name'),
                    'message' => $message,
                ]);
        } catch (ConnectionException $e) {
            Log::error('Nimba SMS : erreur réseau ou timeout', [
                'phone_masked' => self::maskPhone($phoneNumber),
            ]);

            throw new NimbaSmsException('Nimba SMS : erreur réseau ou timeout.', previous: $e);
        }

        if ($response->failed()) {
            // Le corps de réponse peut échoïr certains champs envoyés (selon
            // les APIs) — jamais logué tel quel : le message contient le code
            // OTP en clair. Rédaction défensive avant journalisation, jamais
            // le Secret Token (qui, lui, n'apparaît de toute façon que dans
            // l'en-tête Authorization construit par Http::withBasicAuth(),
            // jamais journalisé ici).
            $safeBody = str($response->body())
                ->replace($message, '[SMS_REDACTED]')
                ->limit(500)
                ->toString();

            Log::error('Nimba SMS : réponse en échec', [
                'status' => $response->status(),
                'phone_masked' => self::maskPhone($phoneNumber),
                'body' => $safeBody,
            ]);

            throw new NimbaSmsException("Nimba SMS : échec de l'envoi (HTTP {$response->status()}).");
        }
    }

    /** Masque un numéro pour les journaux — jamais le numéro complet en clair dans les logs applicatifs. */
    private static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 3).str_repeat('*', strlen($digits) - 5).substr($digits, -2);
    }
}
