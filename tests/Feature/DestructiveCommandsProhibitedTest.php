<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DestructiveCommandsProhibitedTest extends TestCase
{
    protected function tearDown(): void
    {
        // Le flag est statique côté framework (Illuminate\Console\Prohibitable) :
        // le remettre à false évite de casser d'autres tests (ex: RefreshDatabase)
        // qui tournent dans le même process PHP après celui-ci.
        DB::prohibitDestructiveCommands(false);

        parent::tearDown();
    }

    public function test_migrate_fresh_is_blocked_when_destructive_commands_are_prohibited(): void
    {
        DB::prohibitDestructiveCommands(true);

        $exitCode = Artisan::call('migrate:fresh', ['--force' => true]);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString(
            'prohibited from running',
            Artisan::output(),
        );
    }

    public function test_db_wipe_is_blocked_when_destructive_commands_are_prohibited(): void
    {
        DB::prohibitDestructiveCommands(true);

        $exitCode = Artisan::call('db:wipe', ['--force' => true]);

        $this->assertNotSame(0, $exitCode);
    }
}
