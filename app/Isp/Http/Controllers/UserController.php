<?php

namespace App\Isp\Http\Controllers;

use App\Isp\Models\Branch;
use App\Isp\Models\User;
use App\Isp\Services\BandwidthBillingService;
use App\Isp\Services\BranchBillingSettings;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('isp.users.index', [
            'users' => User::with('branch')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('isp.users.form', [
            'panelUser' => new User,
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->saveUser($this->validated($request));

        return redirect()->route('isp.users.index')->with('success', 'Admin user created successfully.');
    }

    public function edit(User $user): View
    {
        return view('isp.users.form', [
            'panelUser' => $user,
            'branches' => Branch::where('is_active', true)
                ->when($user->branch_id, fn ($query) => $query->orWhere('id', $user->branch_id))
                ->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        if ($user->is($request->user()) && ! ($data['is_active'] ?? false)) {
            return back()->withInput()->with('error', 'You cannot deactivate your own account.');
        }
        if ($user->isAdmin() && ($data['role'] !== 'admin' || ! $data['is_active']) && User::where('role', 'admin')->where('is_active', true)->count() <= 1) {
            return back()->withInput()->with('error', 'At least one active administrator is required.');
        }

        $this->saveUser($data, $user);

        return redirect()->route('isp.users.index')->with('success', 'Admin user updated successfully.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot delete your own account.');
        }
        if ($user->isAdmin() && User::where('role', 'admin')->where('is_active', true)->count() <= 1) {
            return back()->with('error', 'At least one active administrator is required.');
        }
        try {
            $user->delete();
        } catch (QueryException $exception) {
            report($exception);

            return back()->with('error', 'This panel user is still linked to historical records and could not be deleted. Run the latest database migrations and try again.');
        }

        return back()->with('success', 'Admin user deleted.');
    }

    private function saveUser(array $data, ?User $user = null): void
    {
        $billingKeys = array_keys(BranchBillingSettings::rules());
        $branch = null;
        $syncBilling = false;
        DB::transaction(function () use ($data, $user, $billingKeys, &$branch, &$syncBilling): void {
            if ($data['role'] === 'branch_operator' && isset($data['billing_type'])) {
                $branch = Branch::lockForUpdate()->findOrFail($data['branch_id']);
                $syncBilling = $branch->isBandwidthOnly() || $data['billing_type'] === 'bandwidth_only';
                $branch->update(BranchBillingSettings::prepare(Arr::only($data, $billingKeys), $branch));
            }
            $attributes = Arr::except($data, $billingKeys);
            if ($user) {
                $user->update($attributes);
            } else {
                User::create($attributes);
            }
        });
        if ($branch && $syncBilling) {
            $failed = app(BandwidthBillingService::class)->reconcile($branch);
            if ($failed) {
                session()->flash('warning', 'User and branch settings saved. Some internet access updates need a retry.');
            }
        }
    }

    private function validated(Request $request, ?User $user = null): array
    {
        // The selected role controls branch billing; retain the existing operator
        // permission role so all customer and payment scoping stays consistent.
        $request->merge(['billing_type' => $request->input('role') === 'bandwidth_only' ? 'bandwidth_only' : 'standard']);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('isp_users', 'email')->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:255', 'confirmed'],
            'role' => ['required', Rule::in(['admin', 'branch_operator', 'bandwidth_only'])],
            'branch_id' => [Rule::requiredIf(in_array($request->input('role'), ['branch_operator', 'bandwidth_only'], true)), 'nullable', 'exists:branches,id'],
            'is_active' => ['nullable', 'boolean'],
        ] + BranchBillingSettings::rules());
        $data['role'] = $data['role'] === 'bandwidth_only' ? 'branch_operator' : $data['role'];
        $data['branch_id'] = $data['role'] === 'branch_operator' ? ($data['branch_id'] ?? null) : null;
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
