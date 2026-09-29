<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Organization;
use Illuminate\Http\Request;

/** Matrix of which module belongs to which organization. */
class ModuleController extends Controller
{
    public function index()
    {
        return view('admin.modules.index', [
            'modules' => Module::with('organizations')->orderBy('name')->get(),
            'organizations' => Organization::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request)
    {
        $matrix = $request->input('matrix', []); // [module_id => [org_id, ...]]
        $global = $request->input('global', []); // [module_id => 1]

        foreach (Module::all() as $module) {
            $module->update(['is_global' => isset($global[$module->id])]);
            $module->organizations()->sync(array_map('intval', $matrix[$module->id] ?? []));
        }

        return back()->with('status', 'Module visibility saved.');
    }
}
