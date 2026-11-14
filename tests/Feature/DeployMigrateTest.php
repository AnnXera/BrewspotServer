<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The CI deploy hook that runs migrations on Hostinger (no SSH there).
 */
class DeployMigrateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.deploy_token' => 'test-deploy-token']);
    }

    public function test_a_wrong_token_is_not_found(): void
    {
        $this->post('/api/deploy/migrate', [], ['X-Deploy-Token' => 'nope'])->assertNotFound();
    }

    public function test_it_runs_migrations(): void
    {
        $this->post('/api/deploy/migrate', [], ['X-Deploy-Token' => 'test-deploy-token'])->assertOk();
    }

    public function test_a_failing_migration_reports_its_error(): void
    {
        Artisan::shouldReceive('call')->once()->andThrow(new \RuntimeException('SQLSTATE[42S21]: Duplicate column name'));
        Artisan::shouldReceive('output')->andReturn("Running migrations.\n");

        $this->post('/api/deploy/migrate', [], ['X-Deploy-Token' => 'test-deploy-token'])
            ->assertStatus(500)
            ->assertSee('RuntimeException: SQLSTATE[42S21]: Duplicate column name', false)
            ->assertSee('Running migrations.', false);
    }
}
