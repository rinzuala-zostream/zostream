<?php

namespace App\Isp\Http\Controllers;

use App\Isp\Models\Branch;
use App\Isp\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        User::create($this->validated($request));

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

        $user->update($data);

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

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('isp_users', 'email')->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:255', 'confirmed'],
            'role' => ['required', Rule::in(['admin', 'branch_operator'])],
            'branch_id' => [Rule::requiredIf($request->input('role') === 'branch_operator'), 'nullable', 'exists:branches,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['branch_id'] = $data['role'] === 'branch_operator' ? ($data['branch_id'] ?? null) : null;
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
