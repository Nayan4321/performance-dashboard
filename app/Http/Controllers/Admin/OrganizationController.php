<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function index()
    {
        return view('admin.organizations.index', [
            'organizations' => Organization::with(['branches' => fn ($q) => $q->orderBy('name'), 'modules'])->withCount('users')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        Organization::create($request->validate(['name' => 'required|string|max:120', 'code' => 'nullable|string|max:30|unique:organizations,code']));

        return back()->with('status', 'Organization added.');
    }

    public function update(Request $request, Organization $organization)
    {
        $organization->update($request->validate([
            'name' => 'required|string|max:120',
            'code' => ['nullable', 'string', 'max:30', Rule::unique('organizations', 'code')->ignore($organization->id)],
        ]) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Organization saved.');
    }

    public function storeBranch(Request $request)
    {
        Branch::create($this->branchData($request));

        return back()->with('status', 'Branch added.');
    }

    public function updateBranch(Request $request, Branch $branch)
    {
        $branch->update($this->branchData($request, $branch));

        return back()->with('status', 'Branch saved.');
    }

    private function branchData(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'organization_id' => 'required|exists:organizations,id',
            'name' => 'required|string|max:120',
            'code' => 'nullable|string|max:30',
            'zenoti_center_id' => ['nullable', 'string', 'max:64', Rule::unique('branches')->ignore($branch?->id)],
            'callgear_site_id' => 'nullable|string|max:64',
            'address' => 'nullable|string|max:255',
        ]) + ['is_active' => $request->boolean('is_active', true), 'is_warehouse' => $request->boolean('is_warehouse')];
    }
}
