@extends('isp.layouts.admin')
@section('title', 'Add Branch')
@section('eyebrow', 'Subscriber organization')

@section('content')
@php
    $globalOperatorPercentage = config('services.zostream_subscription.operator_percentage', 20);
    $oldPackageIds = collect(old('package_ids', []))->map(fn ($id) => (string) $id);
@endphp

<div class="page-actions">
    <div>
        <h2>Add a branch</h2>
        <p>Create a service area and configure its customer defaults.</p>
    </div>
    <a class="button secondary" href="{{ route('isp.branches.index') }}">← Back to branches</a>
</div>

<section class="branch-create-card">
    <div class="branch-section-heading">
        <div class="branch-heading-icon">+</div>
        <div>
            <span>NEW SERVICE AREA</span>
            <h3>Branch details</h3>
            <p>Set the branch defaults once. Operators and new customers will inherit them automatically.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('isp.branches.store') }}" class="branch-create-form">
        @csrf

        <div class="branch-form-section">
            <div class="branch-form-section-title">
                <span>01</span>
                <div>
                    <strong>Branch identity</strong>
                    <small>Name the branch and choose its default MikroTik router.</small>
                </div>
            </div>
            <div class="branch-fields">
                <label class="branch-field">
                    <span>Branch name</span>
                    <input name="name" value="{{ old('name') }}" required maxlength="100" placeholder="e.g. Ngopa" autofocus>
                </label>
                <label class="branch-field">
                    <span>Default router</span>
                    <select name="router_id">
                        <option value="">Choose router</option>
                        @foreach($routers as $router)
                            <option value="{{ $router->id }}" @selected(old('router_id') == $router->id)>{{ $router->name }}</option>
                        @endforeach
                    </select>
                    <small>Branch operators' new customers use this router automatically.</small>
                </label>
            </div>
        </div>

        <div class="branch-form-section">
            <div class="branch-form-section-title">
                <span>02</span>
                <div>
                    <strong>Collection rules</strong>
                    <small>Configure the operator share and optional OTT deduction.</small>
                </div>
            </div>
            <div class="branch-fields">
                <label class="branch-field">
                    <span>Operator share</span>
                    <div class="branch-input-suffix">
                        <input type="number" name="operator_percentage" value="{{ old('operator_percentage') }}" min="0" max="100" step="0.01" placeholder="{{ $globalOperatorPercentage }}">
                        <b>%</b>
                    </div>
                    <small>Leave blank to use the global {{ $globalOperatorPercentage }}% share.</small>
                </label>
                <label class="branch-field">
                    <span>OTT deduction</span>
                    <div class="branch-input-prefix">
                        <b>₹</b>
                        <input type="number" name="ott_deduction" value="{{ old('ott_deduction') }}" min="0" step="0.01" placeholder="0">
                    </div>
                    <small>Leave blank when this branch has no OTT deduction.</small>
                </label>
            </div>
        </div>

        <div class="branch-form-section branch-package-section" data-package-group>
            <div class="branch-form-section-title package-title">
                <span>03</span>
                <div>
                    <strong>Available packages</strong>
                    <small>Choose the plans that operators can assign in this branch.</small>
                </div>
                <div class="package-tools">
                    <button type="button" data-package-all>Select all</button>
                    <button type="button" data-package-clear>Clear</button>
                </div>
            </div>
            <div class="branch-package-grid">
                @forelse($packages as $package)
                    <label class="branch-package-option">
                        <input type="checkbox" name="package_ids[]" value="{{ $package->id }}" @checked($oldPackageIds->contains((string) $package->id))>
                        <span>
                            <strong>{{ $package->name }}</strong>
                            <small>{{ $package->rate_limit ?: 'Unlimited' }}</small>
                        </span>
                        <i>✓</i>
                    </label>
                @empty
                    <div class="branch-packages-empty">Create an active package before limiting branch packages.</div>
                @endforelse
            </div>
            <div class="package-selection-note">
                <span data-package-count>0 selected</span>
                <small>None selected means every active package is available.</small>
            </div>
        </div>

        <div class="branch-create-footer">
            <label class="branch-status-toggle">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
                <span aria-hidden="true"></span>
                <div>
                    <strong>Active branch</strong>
                    <small>Available for customer and operator selection.</small>
                </div>
            </label>
            <button class="button primary branch-submit" type="submit">
                <span>Add branch</span>
                <i aria-hidden="true">→</i>
            </button>
        </div>
    </form>
</section>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-package-group]').forEach(function (group) {
    const inputs = Array.from(group.querySelectorAll('input[name="package_ids[]"]'));
    const count = group.querySelector('[data-package-count]');

    function refreshPackageCount() {
        const selected = inputs.filter(function (input) { return input.checked; }).length;
        if (count) count.textContent = selected === 0 ? 'All packages' : selected + ' selected';
    }

    group.querySelector('[data-package-all]')?.addEventListener('click', function () {
        inputs.forEach(function (input) { input.checked = true; });
        refreshPackageCount();
    });

    group.querySelector('[data-package-clear]')?.addEventListener('click', function () {
        inputs.forEach(function (input) { input.checked = false; });
        refreshPackageCount();
    });

    inputs.forEach(function (input) {
        input.addEventListener('change', refreshPackageCount);
    });

    refreshPackageCount();
});
</script>
@endpush
