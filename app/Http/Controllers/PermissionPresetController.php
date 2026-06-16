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
        $presets = $request->validated()['presets'];

        foreach ([1, 2, 3] as $level) {
            PermissionPreset::updateOrCreate(
                ['tenant_id' => $tenantId, 'level' => $level],
                ['permissions' => array_values($presets[$level] ?? [])],
            );
        }

        return back()->with('success', 'Permission levels updated.');
    }
}
