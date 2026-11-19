<?php

namespace App\Isp\Http\Controllers;

use App\Isp\Models\Branch;
use App\Isp\Models\Package;
use App\Isp\Models\Router;
use App\Isp\Services\BandwidthBillingService;
use App\Isp\Services\BranchBillingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(): View
    {
        return view('isp.branches.index', [
            'branches' => Branch::with(['packages:id,name', 'router:id,name'])->withCount('customers')->orderBy('name')->get(),
            'packages' => Package::where('is_active', true)->orderBy('name')->get(),
            'routers' => Router::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('isp.branches.create', [
            'packages' => Package::where('is_active', true)->orderBy('name')->get(),
            'routers' => Router::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $packageIds] = $this->validated($request);
        $branch = Branch::create($data);
        $branch->packages()->sync($packageIds);

        return to_route('isp.branches.index')->with('success', 'Branch added successfully.');
    }

    public function update(Request $request, Branch $branch, BandwidthBillingService $billing): RedirectResponse
    {
        [$data, $packageIds] = $this->validated($request, $branch);
        $wasBandwidthOnly = $branch->isBandwidthOnly();
        $branch->update($data);
        $branch->packages()->sync($packageIds);

        if ($wasBandwidthOnly || $branch->isBandwidthOnly()) {
            $failed = $billing->reconcile($branch);
            if ($failed) {
                return back()->with('warning', 'Branch saved. Some access updates failed; use customer Sync to retry.');
            }
        }

        return back()->with('success', 'Branch updated successfully.');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        if ($branch->bandwidthInvoices()->exists()) {
            return back()->with('error', 'Branches with bandwidth invoice history cannot be deleted.');
        }
        if ($branch->customers()->exists()) {
            return back()->with('error', 'This branch is assigned to customers. Move those customers before deleting it.');
        }
        if ($branch->users()->exists()) {
            return back()->with('error', 'This branch is assigned to panel users. Move or delete those users first.');
        }

        $branch->delete();

        return back()->with('success', 'Branch deleted.');
    }

    private function validated(Request $request, ?Branch $branch = null): array
    {
        $request->mergeIfMissing(['billing_type' => $branch?->billing_type ?? 'standard']);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('branches', 'name')->ignore($branch)],
            'router_id' => ['nullable', Rule::exists('routers', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'operator_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ott_deduction' => ['nullable', 'numeric', 'min:0'],
            'package_ids' => ['nullable', 'array'],
            'package_ids.*' => ['integer', 'distinct', 'exists:packages,id'],
            'is_active' => ['nullable', 'boolean'],
        ] + BranchBillingSettings::rules());
        $data = BranchBillingSettings::prepare($data, $branch);
        $packageIds = $data['package_ids'] ?? [];
        unset($data['package_ids']);

        return [$data + ['is_active' => $request->boolean('is_active')], $packageIds];
    }
}
