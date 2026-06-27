<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HistoriqueActionsController extends Controller
{
    public const MODULES = [
        'depenses' => 'Dépenses',
        'commissions_logistique' => 'Commission logistique',
        'commissions_vente' => 'Commission vente',
        'commissions_proprietaires' => 'Commission propriétaires',
        'salaires' => 'Salaires',
        'periodes_paiement' => 'Périodes de paiement',
        'fiches_paiement' => 'Fiches de paiement',
        'journal' => 'Journal comptable',
    ];

    public function index(Request $request): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;

        $dateDebut = $request->input('date_debut', '');
        $dateFin = $request->input('date_fin', '');
        $module = $request->input('module', '');
        $eventCode = $request->input('event_code', '');
        $actorId = $request->input('actor_id', '');
        $siteId = $request->input('site_id', '');
        $search = trim((string) $request->input('search', ''));

        $query = AuditLog::where('organization_id', $orgId)
            ->orderByDesc('created_at');

        if ($dateDebut !== '') {
            $query->where('created_at', '>=', $dateDebut.' 00:00:00');
        }
        if ($dateFin !== '') {
            $query->where('created_at', '<=', $dateFin.' 23:59:59');
        }
        if ($eventCode !== '') {
            $query->where('event_code', $eventCode);
        }
        if ($actorId !== '') {
            $query->where('actor_id', $actorId);
        }
        if ($module !== '') {
            $query->whereJsonContains('meta->module', $module);
        }
        if ($siteId !== '') {
            $query->whereJsonContains('meta->site_id', $siteId);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('actor_name_snapshot', 'LIKE', "%{$search}%")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(`meta`, '$.description')) LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(`meta`, '$.module')) LIKE ?", ["%{$search}%"]);
            });
        }

        $logs = $query->paginate(30)->withQueryString();

        $acteurs = User::where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])
            ->values();

        $eventCodes = collect(AuditEvent::cases())
            ->map(fn ($e) => ['value' => $e->value, 'label' => $e->label()])
            ->values();

        $modules = collect(self::MODULES)
            ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
            ->values();

        $sites = Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom'])
            ->map(fn ($s) => ['value' => $s->id, 'label' => $s->nom])
            ->values();

        return Inertia::render('Comptabilite/Historique', [
            'logs' => $logs->through(fn (AuditLog $log) => self::transformLog($log)),
            'filters' => compact('dateDebut', 'dateFin', 'module', 'eventCode', 'actorId', 'siteId', 'search'),
            'acteurs' => $acteurs,
            'event_codes' => $eventCodes,
            'modules' => $modules,
            'sites' => $sites,
        ]);
    }

    public function entite(Request $request): JsonResponse
    {
        $user = auth()->user();
        $module = $request->input('module', '');

        $allowed = match ($module) {
            'depenses' => $user->can('depenses.read') || $user->can('comptabilite.read'),
            default => $user->can('comptabilite.read'),
        };
        abort_unless($allowed, 403);

        $orgId = $user->organization_id;
        $auditableType = $request->input('auditable_type', '');
        $auditableId = $request->input('auditable_id', '');
        $dateDebut = $request->input('date_debut', '');
        $dateFin = $request->input('date_fin', '');
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));

        abort_if($auditableType === '' || $auditableId === '', 400);

        $query = AuditLog::where('organization_id', $orgId)
            ->where('auditable_type', $auditableType)
            ->where('auditable_id', $auditableId)
            ->orderByDesc('created_at');

        if ($module !== '') {
            $query->whereJsonContains('meta->module', $module);
        }

        if ($dateDebut !== '') {
            $query->where('created_at', '>=', $dateDebut.' 00:00:00');
        }

        if ($dateFin !== '') {
            $query->where('created_at', '<=', $dateFin.' 23:59:59');
        }

        if (! empty($siteIds)) {
            $query->where(function ($q) use ($siteIds) {
                foreach ($siteIds as $siteId) {
                    $q->orWhereJsonContains('meta->site_id', $siteId);
                }
            });
        }

        $logs = $query->limit(50)->get()->map(fn (AuditLog $log) => self::transformLog($log));

        return response()->json($logs);
    }

    public static function transformLog(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'event_code' => $log->event_code,
            'event_label' => $log->event_label,
            'actor_name' => $log->actor_name_snapshot ?? 'Système',
            'auditable_type' => $log->auditable_type,
            'auditable_type_label' => class_basename($log->auditable_type ?? ''),
            'auditable_id' => $log->auditable_id,
            'module' => $log->meta['module'] ?? null,
            'module_label' => self::MODULES[$log->meta['module'] ?? ''] ?? ($log->meta['module'] ?? '—'),
            'description' => $log->meta['description'] ?? null,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'meta' => $log->meta,
            'created_at' => $log->created_at?->format('d/m/Y H:i'),
        ];
    }
}
