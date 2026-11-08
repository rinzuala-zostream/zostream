export function watchTime(seconds) {
    if (seconds === null || seconds === undefined) return 'Not available';
    const value = Math.max(0, Number(seconds) || 0);
    if (value > 0 && value < 60) return 'Less than 1 min';
    const hours = Math.floor(value / 3600);
    const minutes = Math.floor(value % 3600 / 60);
    return hours ? `${hours}h ${minutes}m` : `${minutes} min`;
}

export function dataUsage(mb) {
    if (mb === null || mb === undefined) return 'Not recorded';
    const value = Math.max(0, Number(mb) || 0);
    if (value > 0 && value < 1) return 'Less than 1 MB';
    return value >= 1024 ? `${(value / 1024).toFixed(1)} GB` : `${Math.round(value)} MB`;
}

export function dateLabel(value, withTime = false) {
    if (!value) return 'Not recorded';
    const date = new Date(/^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T12:00:00` : value);
    if (Number.isNaN(date.getTime())) return 'Not recorded';
    return new Intl.DateTimeFormat('en-IN', {
        day: 'numeric', month: 'short', year: 'numeric',
        ...(withTime ? { hour: 'numeric', minute: '2-digit' } : {}),
    }).format(date);
}

export function money(amount, currency) {
    if (amount === null || amount === undefined || !Number.isFinite(Number(amount))) return 'Not recorded';
    try {
        if (currency) return new Intl.NumberFormat('en-IN', { style: 'currency', currency }).format(Number(amount));
    } catch { /* Keep the amount visible if an older record has no valid currency. */ }
    return Number(amount).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function statsQuery(range, extras = {}) {
    const query = new URLSearchParams({ period: range.period });
    if (range.period === 'custom') {
        query.set('from', range.from);
        query.set('to', range.to);
    }
    for (const [key, value] of Object.entries(extras)) {
        if (value !== '' && value !== null && value !== undefined) query.set(key, String(value));
    }
    return query.toString();
}

export function watchLink(item) {
    const id = item.movie?.id || item.movie_id;
    return item.watch_url || (id ? `https://app.zostream.in/watch/${encodeURIComponent(id)}` : null);
}
