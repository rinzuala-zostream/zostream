<script>
document.querySelectorAll('[data-billing-settings]').forEach(function (settings) {
    const form = settings.closest('form');
    const type = settings.querySelector('[data-billing-type]');
    const fields = settings.querySelector('[data-bandwidth-fields]');
    const inputs = Array.from(fields.querySelectorAll('input'));
    const shareFields = ['operator_percentage', 'ott_deduction'].map(name => form.querySelector('[name="' + name + '"]')).filter(Boolean);
    function refresh() {
        const bandwidth = type.value === 'bandwidth_only';
        fields.hidden = !bandwidth;
        inputs.forEach(input => { input.disabled = !bandwidth; input.required = bandwidth; });
        shareFields.forEach(input => { input.disabled = bandwidth; input.closest('label').hidden = bandwidth; });
        const amount = Number(form.elements.bandwidth_mbps.value) * Number(form.elements.bandwidth_rate.value);
        const gst = amount * Number(form.elements.bandwidth_gst.value) / 100;
        settings.querySelector('[data-bandwidth-preview]').textContent = 'Monthly: ₹' + amount.toFixed(2) + ' + GST ₹' + gst.toFixed(2) + ' = ₹' + (amount + gst).toFixed(2);
    }
    type.addEventListener('change', refresh);
    inputs.forEach(input => input.addEventListener('input', refresh));
    refresh();
});
</script>
