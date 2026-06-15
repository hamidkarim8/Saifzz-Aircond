<?php

namespace Tests\Feature;

use App\Models\PermissionPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PermissionPresetTest extends TestCase
{
    use RefreshDatabase;

    /** Create a boss admin that is its own tenant root. */
    private function boss(): User
    {
        $boss = User::factory()->admin()->create();
        $boss->update(['tenant_id' => $boss->id]);

        return $boss->fresh();
    }

    public function test_permission_presets_table_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('permission_presets', 'tenant_id'));
        $this->assertTrue(Schema::hasColumn('permission_presets', 'level'));
        $this->assertTrue(Schema::hasColumn('permission_presets', 'permissions'));
    }
}
