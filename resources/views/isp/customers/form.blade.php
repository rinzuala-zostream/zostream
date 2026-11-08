@extends('isp.layouts.admin')
@section('title', $customer->exists ? 'Edit customer' : 'Add customer')
@section('eyebrow', 'Subscriber management')
@section('content')
@php($formStatus = old('status', $customer->exists && $customer->expires_at?->lt(today()) ? 'expired' : ($customer->status ?: 'active')))
<div class="page-actions"><div><h2>{{ $customer->exists ? $customer->name : 'New PPPoE subscriber' }}</h2><p>Saving will also create or update the MikroTik PPP secret.</p></div></div>
@if($packages->isEmpty() || (!auth()->user()->isBranchOperator() && $routers->isEmpty()))<div class="alert warning">You need at least one active router and one active package before adding a customer.</div>@endif
@if(!$customer->exists && auth()->user()->isBranchOperator() && !$operatorBranch?->router_id)<div class="alert warning">Your branch has no default router. Ask an administrator to assign one from the Branches page before adding a customer.</div>@endif
<form id="customerForm" class="form-card form-grid customer-form" method="POST" enctype="multipart/form-data" action="{{ $customer->exists ? route('isp.customers.update', $customer) : route('isp.customers.store') }}" data-complete-url="{{ route('isp.customers.onboarding.cashfree.complete') }}">@csrf @if($customer->exists) @method('PUT') @endif
    @if($customer->exists)<input type="hidden" name="return_to" value="{{ old('return_to', $returnTo ?? route('isp.customers.index')) }}">@endif

    <section class="customer-form-section">
        <div class="customer-form-section-head"><span>01</span><div><strong>Customer details</strong><small>Basic identity and service area information.</small></div></div>
        <div class="customer-form-section-grid">
            <label>Full name<input name="name" value="{{ old('name', $customer->name) }}" required placeholder="Customer name"></label>
            <label>Phone<input name="phone" value="{{ old('phone', $customer->phone) }}" placeholder="+91..." @required(! $customer->exists)></label>
            <label class="full">Branch<select id="customerBranch" name="branch_id" @disabled(auth()->user()->isBranchOperator())><option value="" data-package-ids="[]">No branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" data-package-ids='@json($branch->packages->pluck("id")->values())' @selected((string) old('branch_id', $customer->branch_id ?: auth()->user()->branch_id) === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select>@if(auth()->user()->isBranchOperator())<input type="hidden" name="branch_id" value="{{ auth()->user()->branch_id }}"><small class="form-help">Your account is restricted to this branch.</small>@else<small class="form-help">Package choices change according to the selected branch.</small>@endif</label>
        </div>
    </section>

    <section class="customer-form-section">
        <div class="customer-form-section-head"><span>02</span><div><strong>Internet service</strong><small>Router, package and PPPoE access settings.</small></div></div>
        <div class="customer-form-section-grid">
            @if($customer->exists)
                <label>Network router<select disabled><option>{{ $customer->router->name }}</option></select><input type="hidden" name="router_id" value="{{ $customer->router_id }}"><small class="form-help">A synced customer cannot be moved silently to another router.</small></label>
            @elseif(!auth()->user()->isBranchOperator())
                <label>Network router<select name="router_id" required><option value="">Choose router</option>@foreach($routers as $router)<option value="{{ $router->id }}" @selected(old('router_id') == $router->id)>{{ $router->name }}</option>@endforeach</select></label>
            @endif
            <label>Package<select id="customerPackage" name="package_id" required><option value="">Choose package</option>@foreach($packages as $package)<option value="{{ $package->id }}" @selected(old('package_id', $customer->package_id) == $package->id)>{{ $package->name }} · ₹{{ number_format($package->price, 0) }}</option>@endforeach</select><small id="packageAvailability" class="form-help"></small></label>
            <label>PPPoE username<input name="username" value="{{ old('username', $customer->username) }}" required autocomplete="off" placeholder="customer001">@if($customer->exists)<small class="form-help">Changing this updates the customer's RADIUS login when you save.</small>@endif</label>
            <label>PPPoE password<input type="password" name="password" {{ $customer->exists ? '' : 'required' }} autocomplete="new-password" placeholder="{{ $customer->exists ? 'Leave blank to keep current password' : 'PPPoE password' }}"></label>
            <label>Administrative status<select name="status"><option value="active" @selected($formStatus === 'active')>Active</option><option value="suspended" @selected($formStatus === 'suspended')>Suspended</option><option value="expired" @selected($formStatus === 'expired')>Expired</option></select><small class="form-help">Expired immediately ends access by setting the plan expiry date.</small></label>
            @if(auth()->user()->isAdmin())
                <label>Expiry date<input type="date" name="expires_at" value="{{ old('expires_at', $customer->expires_at?->toDateString()) }}"><small class="form-help">Administrator only. The selected date remains active through the end of that day.</small></label>
            @endif
            <div class="form-help full">New customers receive 30 days automatically unless an administrator selects an expiry date. Future payments extend an active plan from its current expiry date.</div>
        </div>
    </section>

    <section class="customer-form-section customer-router-section">
        <div class="customer-form-section-head"><span>03</span><div><strong>Customer router</strong><small>Record the device condition and collect payment only when required.</small></div></div>
        <div class="customer-form-section-grid">
            <label class="full">Router condition<select id="routerDeviceCondition" name="router_device_condition" required @disabled($customer->exists && $customer->router_device_condition)><option value="">Choose old or new</option><option value="old" @selected(old('router_device_condition', $customer->router_device_condition) === 'old')>Old router — no payment required</option><option value="new" @selected(old('router_device_condition', $customer->router_device_condition) === 'new')>New router</option></select>@if($customer->exists && $customer->router_device_condition)<input type="hidden" name="router_device_condition" value="{{ $customer->router_device_condition }}">@endif<small class="form-help">This is the device supplied to the customer, not the network router above.</small></label>
            @unless($customer->exists)
                <label id="routerPaymentField" hidden>Payment option<select id="routerPaymentChoice" name="router_payment_choice"><option value="">Choose payment option</option><option value="pay_now" @selected(old('router_payment_choice') === 'pay_now')>Pay now with Cashfree</option><option value="pay_later" @selected(old('router_payment_choice') === 'pay_later')>Pay later</option></select></label>
                <label id="routerAmountField" hidden>Router amount<input id="routerAmount" type="number" name="router_amount" min="1" max="999999.99" step="0.01" value="{{ old('router_amount') }}" placeholder="Amount in ₹"></label>
                <label id="routerNoteField" class="full" hidden>Reason for paying later<textarea id="routerPaymentNote" name="router_payment_note" placeholder="Write why the new router payment is deferred">{{ old('router_payment_note') }}</textarea></label>
            @endunless
        </div>
    </section>

    <section class="customer-form-section">
        <div class="customer-form-section-head"><span>04</span><div><strong>Documents & installation</strong><small>Aadhaar front and back images are required. QR verification is not performed.</small></div></div>
        <div class="customer-form-section-grid">
            <div class="aadhaar-verification-note full"><span>✓</span><div><strong>Aadhaar documents required</strong><small>Upload clear front and back images. The images are stored with the customer record; the QR is not verified.</small></div></div>
            <div id="aadhaarUploadError" class="aadhaar-verification-error full" role="alert" tabindex="-1" @if(!$errors->hasAny(['aadhaar_front', 'aadhaar_back'])) hidden @endif>
                <span aria-hidden="true">!</span>
                <div>
                    <strong>Aadhaar images are required</strong>
                    <p data-aadhaar-error-message>{{ $errors->first('aadhaar_front') ?: $errors->first('aadhaar_back') }}</p>
                    <small>Choose valid JPG or PNG images for both front and back, up to 5 MB each.</small>
                </div>
            </div>
            <div class="aadhaar-upload-field" data-aadhaar-upload>
                <label>Aadhaar front<input data-aadhaar-input type="file" name="aadhaar_front" accept=".jpg,.jpeg,.png,image/jpeg,image/png" @required(! $customer->exists || ! $customer->aadhaar_front_path)><small class="form-help">JPG or PNG; maximum 5 MB.</small>@if($customer->exists && $customer->aadhaar_front_path)<a class="text-link" href="{{ route('isp.customers.document', [$customer, 'front']) }}">Download current front</a>@endif</label>
                @php($frontPreview = $customer->exists && $customer->aadhaar_front_path ? route('isp.customers.document', [$customer, 'front', 'preview' => 1]) : '')
                <div class="aadhaar-image-preview"><img data-aadhaar-preview data-existing-src="{{ $frontPreview }}" src="{{ $frontPreview }}" alt="Aadhaar front preview" @if(!$frontPreview) hidden @endif><span data-aadhaar-empty @if($frontPreview) hidden @endif>No front image selected</span></div>
            </div>
            <div class="aadhaar-upload-field" data-aadhaar-upload>
                <label>Aadhaar back<input data-aadhaar-input type="file" name="aadhaar_back" accept=".jpg,.jpeg,.png,image/jpeg,image/png" @required(! $customer->exists || ! $customer->aadhaar_back_path)><small class="form-help">JPG or PNG; maximum 5 MB.</small>@if($customer->exists && $customer->aadhaar_back_path)<a class="text-link" href="{{ route('isp.customers.document', [$customer, 'back']) }}">Download current back</a>@endif</label>
                @php($backPreview = $customer->exists && $customer->aadhaar_back_path ? route('isp.customers.document', [$customer, 'back', 'preview' => 1]) : '')
                <div class="aadhaar-image-preview"><img data-aadhaar-preview data-existing-src="{{ $backPreview }}" src="{{ $backPreview }}" alt="Aadhaar back preview" @if(!$backPreview) hidden @endif><span data-aadhaar-empty @if($backPreview) hidden @endif>No back image selected</span></div>
            </div>
            <label class="full">Installation address<textarea name="address" placeholder="House, locality, landmark">{{ old('address', $customer->address) }}</textarea></label>
        </div>
    </section>

    <div class="form-actions customer-form-actions"><a class="button secondary" href="{{ $customer->exists ? ($returnTo ?? route('isp.customers.index')) : route('isp.customers.index') }}">Cancel</a><button id="customerSubmitButton" class="button primary" @disabled($packages->isEmpty() || (!auth()->user()->isBranchOperator() && $routers->isEmpty()) || (! $customer->exists && auth()->user()->isBranchOperator() && ! $operatorBranch?->router_id))>Save & sync</button></div>
</form>
@endsection
@push('scripts')
@unless($customer->exists)<script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>@endunless
<script>
(() => {
    document.querySelectorAll('[data-aadhaar-upload]').forEach(field => {
        const input = field.querySelector('[data-aadhaar-input]');
        const image = field.querySelector('[data-aadhaar-preview]');
        const empty = field.querySelector('[data-aadhaar-empty]');
        let objectUrl = null;

        input?.addEventListener('change', () => {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = null;
            const file = input.files?.[0];
            if (file) {
                objectUrl = URL.createObjectURL(file);
                image.src = objectUrl;
                image.hidden = false;
                empty.hidden = true;
                return;
            }

            const existingSrc = image.dataset.existingSrc;
            image.src = existingSrc || '';
            image.hidden = !existingSrc;
            empty.hidden = Boolean(existingSrc);
        });
    });
})();

(() => {
    const branch = document.getElementById('customerBranch');
    const packageSelect = document.getElementById('customerPackage');
    const help = document.getElementById('packageAvailability');
    if (!branch || !packageSelect) return;
    const filterPackages = () => {
        const option = branch.selectedOptions[0];
        const allowed = JSON.parse(option?.dataset.packageIds || '[]').map(String);
        const unrestricted = allowed.length === 0;
        let visible = 0;
        [...packageSelect.options].forEach((item, index) => {
            if (index === 0) return;
            const show = unrestricted || allowed.includes(item.value);
            item.hidden = !show;
            item.disabled = !show;
            if (show) visible++;
        });
        if (packageSelect.selectedOptions[0]?.disabled) packageSelect.value = '';
        help.textContent = unrestricted
            ? 'All active packages are available for this branch.'
            : `${visible} package${visible === 1 ? '' : 's'} available for ${option.textContent.trim()}.`;
    };
    branch.addEventListener('change', filterPackages);
    filterPackages();
})();

(() => {
    const form = document.getElementById('customerForm');
    const condition = document.getElementById('routerDeviceCondition');
    const amountField = document.getElementById('routerAmountField');
    const amount = document.getElementById('routerAmount');
    const paymentField = document.getElementById('routerPaymentField');
    const payment = document.getElementById('routerPaymentChoice');
    const noteField = document.getElementById('routerNoteField');
    const note = document.getElementById('routerPaymentNote');
    const button = document.getElementById('customerSubmitButton');
    const aadhaarError = document.getElementById('aadhaarUploadError');
    const aadhaarErrorMessage = aadhaarError?.querySelector('[data-aadhaar-error-message]');
    if (!form || !condition || !amount || !payment || !note) return;

    const showAadhaarError = message => {
        if (!aadhaarError || !aadhaarErrorMessage) return;
        aadhaarErrorMessage.textContent = message;
        aadhaarError.hidden = false;
        aadhaarError.scrollIntoView({behavior: 'smooth', block: 'center'});
        aadhaarError.focus({preventScroll: true});
    };

    const refreshRouterFields = () => {
        const isNew = condition.value === 'new';
        const payNow = isNew && payment.value === 'pay_now';
        const payLater = isNew && payment.value === 'pay_later';
        amountField.hidden = !payNow;
        paymentField.hidden = !isNew;
        noteField.hidden = !payLater;
        amount.disabled = !payNow;
        payment.disabled = !isNew;
        note.disabled = !payLater;
        amount.required = payNow;
        payment.required = isNew;
        note.required = payLater;
        button.textContent = payNow ? 'Pay router & add customer' : 'Save & sync';
    };
    condition.addEventListener('change', refreshRouterFields);
    payment.addEventListener('change', refreshRouterFields);
    refreshRouterFields();

    let busy = false;
    form.addEventListener('submit', async event => {
        if (condition.value !== 'new' || payment.value !== 'pay_now') return;
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        if (typeof Cashfree === 'undefined') {
            alert('Cashfree Checkout could not be loaded. Check the internet connection and retry.');
            return;
        }

        busy = true;
        button.disabled = true;
        if (aadhaarError) aadhaarError.hidden = true;
        button.textContent = 'Preparing payment…';
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            });
            const pending = await response.json();
            if (!response.ok) {
                const validation = pending.errors ? Object.values(pending.errors).flat().join('\n') : '';
                const aadhaarValidation = [
                    ...(pending.errors?.aadhaar_front || []),
                    ...(pending.errors?.aadhaar_back || []),
                ].join('\n');
                if (aadhaarValidation) showAadhaarError(aadhaarValidation);
                const failure = new Error(validation || pending.message || 'Unable to start router payment.');
                failure.shownInline = Boolean(aadhaarValidation);
                throw failure;
            }

            button.textContent = 'Opening secure payment…';
            const cashfree = Cashfree({mode: pending.mode});
            const checkoutResult = await cashfree.checkout({
                paymentSessionId: pending.payment_session_id,
                redirectTarget: '_modal',
            });
            button.textContent = 'Verifying & creating customer…';
            const completed = await fetch(form.dataset.completeUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    onboarding_id: pending.onboarding_id,
                    order_id: pending.order_id,
                }),
            });
            const result = await completed.json();
            if (!completed.ok) {
                throw new Error(result.message || checkoutResult?.error?.message || 'Router payment is not completed yet.');
            }
            window.location.href = result.redirect;
        } catch (error) {
            busy = false;
            button.disabled = false;
            refreshRouterFields();
            if (!error.shownInline) alert(error.message);
        }
    });
})();
</script>
@endpush
