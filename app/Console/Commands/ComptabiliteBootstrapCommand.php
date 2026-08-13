<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Console\Command;

class ComptabiliteBootstrapCommand extends Command
{
    protected $signature = 'comptabilite:bootstrap {organization? : ID d\'une organisation ; toutes si omis}';

    protected $description = 'Provisionne le plan comptable, les journaux et les mappings par défaut (comptabilité générale). Idempotent.';

    public function handle(PlanComptableBootstrapService $bootstrap): int
    {
        $organizationId = $this->argument('organization');

        $organizations = $organizationId
            ? Organization::query()->where('id', $organizationId)->get()
            : Organization::query()->get();

        if ($organizations->isEmpty()) {
            $this->error('Aucune organisation trouvée.');

            return self::FAILURE;
        }

        foreach ($organizations as $organization) {
            $bootstrap->bootstrap($organization->id);
            $this->info("Comptabilité provisionnée pour « {$organization->name} » ({$organization->id}).");
        }

        return self::SUCCESS;
    }
}
