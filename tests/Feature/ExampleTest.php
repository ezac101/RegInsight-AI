<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_gaid_homepage_loads_successfully(): void
    {
        $this->withoutVite();

        $response = $this->get(route('gaid.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Gaid/Index'));
    }
}
