@extends('isp.layouts.admin')
@section('title', $panelUser->exists ? 'Edit panel user' : 'Add panel user')
@section('eyebrow', 'Access control')
@section('content')
<div class="editor-heading">
    <a href="{{ route('isp.users.index') }}">←</a>
    <div><span>PANEL ACCESS</span><h2>{{ $panelUser->exists ? $panelUser->name : 'New panel user' }}</h2><p>Create an administrator or restrict an operator to one branch.</p></div>
</div>
<form class="module-editor" method="POST" action="{{ $panelUser->exists ? route('isp.users.update', $panelUser) : route('isp.users.store') }}">
    @csrf @if($panelUser->exists) @method('PUT') @endif
    <div class="editor-section">
        <div class="editor-section-copy"><span>01</span><div><strong>User identity</strong><small>Name and email used to sign in.</small></div></div>
        <div class="editor-fields">
            <label><span>Full name</span><input name="name" value="{{ old('name', $panelUser->name) }}" required maxlength="150"></label>
            <label><span>Email address</span><input type="email" name="email" value="{{ old('email', $panelUser->email) }}" required></label>
        </div>
    </div>
    <div class="editor-section">
        <div class="editor-section-copy"><span>02</span><div><strong>Role and scope</strong><small>Choose full admin access or one branch only.</small></div></div>
        <div class="editor-fields">
            <label><span>Role</span><select id="role" name="role" required><option value="admin" @selected(old('role', $panelUser->panelRole() ?: 'branch_operator') === 'admin')>Administrator</option><option value="branch_operator" @selected(old('role', $panelUser->panelRole() ?: 'branch_operator') === 'branch_operator')>Branch operator</option><option value="bandwidth_only" @selected(old('role', $panelUser->panelRole() ?: 'branch_operator') === 'bandwidth_only')>Bandwidth Only</option></select></label>
            <label id="branchField"><span>Assigned branch</span><select name="branch_id"><option value="">Choose branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" data-billing-type="{{ $branch->billing_type ?? 'standard' }}" data-bandwidth-mbps="{{ $branch->bandwidth_mbps }}" data-bandwidth-rate="{{ $branch->bandwidth_rate }}" data-bandwidth-gst="{{ $branch->bandwidth_gst ?? 18 }}" data-bandwidth-due-day="{{ $branch->bandwidth_due_day ?? 10 }}" @selected((string) old('branch_id', $panelUser->branch_id) === (string) $branch->id)>{{ $branch->name }}{{ $branch->isBandwidthOnly() ? ' · Bandwidth Only' : '' }}</option>@endforeach</select><small>Operators see only customers and payments in this branch. Billing settings below apply to all operators and customers in the selected branch.</small></label>
        </div>
    </div>
    <div class="editor-section" id="operatorBillingSection">
        <div class="editor-section-copy"><span>₹</span><div><strong>Operator billing</strong><small>Configure the assigned branch. Existing branch operators keep their current billing.</small></div></div>
        @include('isp.branches.billing-fields', ['billingBranch' => $panelUser->branch, 'roleControlsBilling' => true])
    </div>
    <div class="editor-section">
        <div class="editor-section-copy"><span>03</span><div><strong>Sign-in security</strong><small>{{ $panelUser->exists ? 'Leave both password fields blank to keep the current password.' : 'Use at least eight characters.' }}</small></div></div>
        <div class="editor-fields">
            <label><span>Password</span><input type="password" name="password" {{ $panelUser->exists ? '' : 'required' }} minlength="8" autocomplete="new-password" placeholder="{{ $panelUser->exists ? 'Keep current password' : 'At least 8 characters' }}"></label>
            <label><span>Confirm password</span><input type="password" name="password_confirmation" {{ $panelUser->exists ? '' : 'required' }} minlength="8" autocomplete="new-password"></label>
        </div>
    </div>
    <div class="editor-footer">
        <label class="editor-toggle"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $panelUser->exists ? $panelUser->is_active : true))><span></span><div><strong>Sign-in allowed</strong><small>Disable to block this account without deleting it.</small></div></label>
        <div><a class="button secondary" href="{{ route('isp.users.index') }}">Cancel</a><button class="button primary">Save user</button></div>
    </div>
</form>
@endsection
@push('scripts')
@include('isp.branches.billing-script')
<script>
(() => {
    const role = document.getElementById('role');
    const branch = document.getElementById('branchField');
    const billing = document.getElementById('operatorBillingSection');
    const assignedBranch = branch.querySelector('select');
    const settings = billing.querySelector('[data-billing-settings]');
    const type = settings.querySelector('[name="billing_type"]');
    function hydrate() {
        const option = assignedBranch.selectedOptions[0];
        ['bandwidth_mbps', 'bandwidth_rate', 'bandwidth_gst', 'bandwidth_due_day'].forEach(name => {
            const key = name.replace(/_([a-z])/g, (_, letter) => letter.toUpperCase());
            settings.querySelector('[name="' + name + '"]').value = option?.dataset[key] || '';
        });
        update();
    }
    const update = () => {
        const operator = role.value === 'branch_operator' || role.value === 'bandwidth_only';
        const bandwidth = role.value === 'bandwidth_only';
        type.value = bandwidth ? 'bandwidth_only' : 'standard';
        branch.hidden = !operator;
        billing.hidden = !bandwidth;
        settings.querySelectorAll('input, select').forEach(input => input.disabled = !operator);
        if (operator) type.dispatchEvent(new Event('change'));
    };
    assignedBranch.addEventListener('change', hydrate);
    if (!@json(session()->hasOldInput())) hydrate();
    role.addEventListener('change', update);
    update();
})();
</script>
@endpush
