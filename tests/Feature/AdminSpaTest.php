<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminSpaTest extends TestCase
{
    public function test_admin_entry_route_serves_the_vue_application(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertSee('id="app"', false)
            ->assertSee('Zo Stream Admin');
    }

    public function test_nested_admin_routes_fall_back_to_the_vue_application(): void
    {
        $this->get('/admin/movies/add')
            ->assertOk()
            ->assertSee('id="app"', false);
    }
}
