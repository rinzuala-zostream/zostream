@php($billingBranch = $billingBranch ?? null)
<div data-billing-settings class="branch-fields">
    @if($roleControlsBilling ?? false)
        <input type="hidden" name="billing_type" data-billing-type value="{{ old('role', $panelUser->panelRole() ?: 'branch_operator') === 'bandwidth_only' ? 'bandwidth_only' : 'standard' }}">
    @else
    <label class="branch-field">
        <span>Operator type</span>
        <select name="billing_type" data-billing-type>
            <option value="standard" @selected(old('billing_type', $billingBranch?->billing_type ?? 'standard') === 'standard')>Branch operator</option>
            <option value="bandwidth_only" @selected(old('billing_type', $billingBranch?->billing_type) === 'bandwidth_only')>Bandwidth Only</option>
        </select>
    </label>
    @endif
    <div data-bandwidth-fields>
        <label class="branch-field"><span>Bandwidth (Mbps)</span><input name="bandwidth_mbps" type="number" min="0.01" max="9999999.99" step="0.01" value="{{ old('bandwidth_mbps', $billingBranch?->bandwidth_mbps) }}"></label>
        <label class="branch-field"><span>Monthly rate per Mbps (₹)</span><input name="bandwidth_rate" type="number" min="0.01" max="999999.99" step="0.01" value="{{ old('bandwidth_rate', $billingBranch?->bandwidth_rate) }}"></label>
        <label class="branch-field"><span>GST (%)</span><input name="bandwidth_gst" type="number" min="0" max="100" step="0.01" value="{{ old('bandwidth_gst', $billingBranch?->bandwidth_gst ?? 18) }}"></label>
        <label class="branch-field"><span>Payment due day each month (1–31)</span><input name="bandwidth_due_day" type="number" min="1" max="31" step="1" value="{{ old('bandwidth_due_day', $billingBranch?->bandwidth_due_day ?? 10) }}"></label>
        <small>Previous-month invoices are issued on the 1st. A due day beyond the month ends on its last day. Internet is blocked after the due date. Billing begins this month; the first invoice is issued next month, at the full monthly rate.</small>
        <p data-bandwidth-preview></p>
    </div>
</div>
