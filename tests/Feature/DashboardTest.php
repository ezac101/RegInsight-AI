<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_ndpc_dashboard_is_accessible_without_authentication(): void
    {
        $this->withoutVite();

        $response = $this->get(route('gaid.ndpc'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Gaid/NdpcDashboard'));
    }
}
