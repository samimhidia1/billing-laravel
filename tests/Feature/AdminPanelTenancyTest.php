<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTenancyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression: the admin panel used to enable Team tenancy, which made Filament
     * apply a "where team_id = <tenant>" global scope to global system models
     * (Role, SiteSettings, Menu, AuditLog, Subscription, ...) that have no team
     * relationship/column, 500ing every authenticated request. The admin panel is
     * now a global super-admin panel with no tenancy.
     */
    public function test_admin_panel_is_not_tenant_scoped(): void
    {
        $this->assertFalse(
            Filament::getPanel('admin')->hasTenancy(),
            'The admin panel must not be tenant-scoped; its resources are global system entities.'
        );
    }

    public function test_every_admin_resource_query_runs_without_tenant_scope_error(): void
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        $panel->boot();

        $failures = [];

        foreach ($panel->getResources() as $resource) {
            if (! is_subclass_of($resource, Resource::class)) {
                continue;
            }

            try {
                $resource::getEloquentQuery()->count();
            } catch (\Throwable $e) {
                // Only tenancy-scope failures are in scope for this regression: the
                // "relationship named [team]" error and the "unknown column team_id"
                // SQL error Filament produced when scoping global models. Unrelated
                // problems (e.g. a model whose table was never migrated) are ignored.
                $message = $e->getMessage();

                if (str_contains($message, 'relationship named [team]') || str_contains($message, 'team_id')) {
                    $failures[] = class_basename($resource).': '.$message;
                }
            }
        }

        $this->assertSame([], $failures, "Admin resources still have a tenant-scope error:\n".implode("\n", $failures));
    }

    public function test_authenticated_user_can_load_admin_panel_without_server_error(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->get('/admin');

        $this->assertLessThan(500, $response->getStatusCode(), 'Admin panel returned a server error: '.$response->getStatusCode());
    }
}
