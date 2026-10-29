<?php

namespace Tests\Feature\Concerns;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Fait échouer l'écriture du journal à partir de maintenant : sert à vérifier qu'une opération
 * interrompue en cours de route ne laisse aucune donnée partiellement enregistrée.
 */
trait SimuleUnePanneDuJournal
{
    private function simulerPanneDuJournal(): void
    {
        $this->app->instance(AuditLogService::class, new class extends AuditLogService
        {
            public function record(
                Model $auditable,
                AuditEvent $event,
                ?User $actor,
                ?array $oldValues = null,
                ?array $newValues = null,
                array $meta = [],
                ?string $organizationIdOverride = null,
            ): AuditLog {
                throw new RuntimeException('Panne simulée du journal');
            }
        });
    }
}
