<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class RegistrationTest extends TestCase
{
    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }
}
