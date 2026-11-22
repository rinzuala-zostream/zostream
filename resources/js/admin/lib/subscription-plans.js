// Plan choices must include every page, regardless of the device category.
export async function loadSubscriptionPlans(request) {
    const plans = new Map();
    let page = 1;
    let lastPage = 1;
    do {
        const response = await request(`/admin/plans?per_page=100&page=${page}`);
        const payload = response?.status === 'success' ? response.data : response;
        const rows = Array.isArray(payload) ? payload : payload?.data || [];
        rows.forEach(plan => plans.set(String(plan.id), plan));
        lastPage = Number(payload?.last_page || 1);
        page += 1;
    } while (page <= lastPage);
    return [...plans.values()];
}
