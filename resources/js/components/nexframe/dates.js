// Use one explicit reporting timezone for dates throughout the partner dashboard.
export const reportingTimezone = 'Asia/Kolkata';

export function formatDate(value, { includeTime = false, short = false } = {}) {
    if (!value) return '—';
    const date = new Date(/^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T00:00:00+05:30` : value);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat('en-IN', {
        timeZone: reportingTimezone,
        day: 'numeric', month: 'short',
        ...(!short && { year: 'numeric' }),
        ...(includeTime && { hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }),
    }).format(date);
}

export const formatDateTime = (value) => formatDate(value, { includeTime: true });
export const formatDateRange = (from, to) => from === to ? formatDate(from) : `${formatDate(from)} – ${formatDate(to)}`;

export function normalizeReportRange(range, minimum, today) {
    if (minimum && minimum > today) throw new Error(`The partnership starts on ${formatDate(minimum)}. Reports are not available yet.`);
    let from = range.from || minimum || today;
    let to = range.to || today;
    if (minimum && from < minimum) from = minimum;
    if (from > today) from = today;
    if (to > today) to = today;
    if (to < from) to = from;
    return { from, to };
}
