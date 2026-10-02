<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_redirects_to_the_panel(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/admin');
    }
}
