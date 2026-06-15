<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePermissionPresetRequest;
use App\Models\PermissionPreset;
use Illuminate\Http\RedirectResponse;

class PermissionPresetController extends Controller
{
    public function update(UpdatePermissionPresetRequest $request): RedirectResponse
    {
        $tenantId = $request->user()->tenantId();

        foreach ($request->validated()['presets'] as $level => $permissions) {
            PermissionPreset::updateOrCreate(
                ['tenant_id' => $tenantId, 'level' => (int) $level],
                ['permissions' => array_values($permissions)],
            );
        }

        return back()->with('success', 'Permission levels updated.');
    }
}
