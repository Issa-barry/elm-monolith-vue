<?php

namespace App\Http\Controllers;

use App\Enums\MessageDirection;
use App\Enums\MessageLogStatus;
use App\Enums\OtpChannel;
use App\Models\MessageLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Écran de monitoring des envois SMS/WhatsApp (cf. rapport monitoring
 * Communications, 07/09/2026) — consultation seule, jamais de contenu de
 * message ni de code OTP exposé (cf. App\Models\MessageLog). P1 : uniquement
 * les SMS OTP réellement transportés via Nimba (cf. App\Jobs\SendSmsOtpJob).
 */
class CommunicationController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(auth()->user()->can('communications.read'), 403);

        $orgId = auth()->user()->organization_id;

        // `organization_id` est nullable sur `users` (nullOnDelete si l'organisation
        // d'un compte est supprimée) — sans cette garde, `where('organization_id',
        // $orgId)` avec `$orgId === null` serait réécrit par Eloquent en
        // `whereNull('organization_id')` (comportement natif du query builder dès que
        // la valeur passée est `null`) et afficherait toutes les lignes `message_logs`
        // SANS organisation (ex: OTP de vérification téléphone avant création de
        // compte, cf. MessageLogService::logSmsOtpAttempt()) à ce compte — jamais les
        // communications d'une autre organisation identifiée, mais un croisement de
        // données non voulu. Un compte sans organisation n'a aucun périmètre légitime
        // à consulter ici.
        abort_unless($orgId !== null, 403);

        $channel = $request->input('channel', '');
        $direction = $request->input('direction', '');
        $status = $request->input('status', '');
        $search = trim((string) $request->input('search', ''));
        $dateDebut = $request->input('date_debut', '');
        $dateFin = $request->input('date_fin', '');

        $query = MessageLog::where('organization_id', $orgId)
            ->orderByDesc('created_at');

        if ($channel !== '') {
            $query->where('channel', $channel);
        }
        if ($direction !== '') {
            $query->where('direction', $direction);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $query->where('masked_recipient', 'LIKE', "%{$search}%");
        }
        if ($dateDebut !== '') {
            $query->where('created_at', '>=', $dateDebut.' 00:00:00');
        }
        if ($dateFin !== '') {
            $query->where('created_at', '<=', $dateFin.' 23:59:59');
        }

        $logs = $query->paginate(30)->withQueryString();

        return Inertia::render('Communications/Index', [
            'logs' => $logs->through(fn (MessageLog $log) => self::transform($log)),
            'filters' => compact('channel', 'direction', 'status', 'search', 'dateDebut', 'dateFin'),
            'channels' => collect(OtpChannel::cases())
                ->map(fn (OtpChannel $c) => ['value' => $c->value, 'label' => ucfirst($c->value)])
                ->values(),
            'directions' => collect(MessageDirection::cases())
                ->map(fn (MessageDirection $d) => ['value' => $d->value, 'label' => $d->label()])
                ->values(),
            'statuses' => collect(MessageLogStatus::cases())
                ->map(fn (MessageLogStatus $s) => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
        ]);
    }

    public static function transform(MessageLog $log): array
    {
        return [
            'id' => $log->id,
            'channel' => $log->channel->value,
            'channel_label' => ucfirst($log->channel->value),
            'direction' => $log->direction->value,
            'direction_label' => $log->direction->label(),
            'purpose' => $log->purpose?->value,
            'purpose_label' => self::purposeLabel($log),
            'provider' => $log->provider,
            'provider_message_id' => $log->provider_message_id,
            'masked_recipient' => $log->masked_recipient,
            'status' => $log->status->value,
            'status_label' => $log->status->label(),
            'provider_status' => $log->provider_status,
            'error_code' => $log->error_code,
            'error_message' => $log->error_message,
            'created_at' => $log->created_at?->format('d/m/Y H:i:s'),
            'sent_at' => $log->sent_at?->format('d/m/Y H:i:s'),
            'failed_at' => $log->failed_at?->format('d/m/Y H:i:s'),
        ];
    }

    private static function purposeLabel(MessageLog $log): string
    {
        return match ($log->purpose?->value) {
            'login' => 'Connexion',
            'phone_verification' => 'Vérification téléphone',
            'password_reset' => 'Réinitialisation mot de passe',
            'email_verification' => 'Vérification email',
            'invitation' => 'Invitation',
            default => '—',
        };
    }
}
