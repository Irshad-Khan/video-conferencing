<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_reports_ok(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)->assertExactJson(['status' => 'ok']);
    }
}
