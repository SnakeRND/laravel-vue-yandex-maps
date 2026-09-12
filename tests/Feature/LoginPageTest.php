<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_route_is_reachable(): void
    {
        $this->withoutVite();

        $response = $this->get('/login');

        $response->assertStatus(200);
    }
}
