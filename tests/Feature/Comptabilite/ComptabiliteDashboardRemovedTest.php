<?php

namespace Tests\Feature\Comptabilite;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ComptabiliteDashboardRemovedTest extends TestCase
{
    public function test_ancien_tableau_de_bord_comptable_nest_plus_servi(): void
    {
        $this->assertFalse(Route::has('comptabilite.dashboard'));
        $this->get('/backoffice/comptabilite')->assertNotFound();
    }
}
