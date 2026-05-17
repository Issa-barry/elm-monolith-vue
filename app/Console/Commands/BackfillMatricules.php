<?php

namespace App\Console\Commands;

use App\Http\Controllers\UserController;
use App\Models\User;
use App\Services\MatriculeService;
use Illuminate\Console\Command;

class BackfillMatricules extends Command
{
    protected $signature = 'users:backfill-matricules';

    protected $description = 'Assigns matricules to existing staff users who do not have one yet.';

    public function handle(MatriculeService $service): int
    {
        $users = User::whereNull('matricule')
            ->whereNotNull('organization_id')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', UserController::STAFF_ROLES))
            ->orderBy('created_at')
            ->get();

        if ($users->isEmpty()) {
            $this->info('No staff users without a matricule found.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($users->count());
        $bar->start();

        foreach ($users as $user) {
            $service->assignForUser($user);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("{$users->count()} matricule(s) assigned.");

        return self::SUCCESS;
    }
}
