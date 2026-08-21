const dateFormatter = new Intl.DateTimeFormat('en-IN', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
});

const dateTimeFormatter = new Intl.DateTimeFormat('en-IN', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
});

export function formatAdminDate(value, includeTime = undefined) {
    if (value == null || value === '') return '—';

    const text = String(value).trim();
    const parts = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T\s](\d{2}):(\d{2})(?::(\d{2}))?)?/);
    let date;
    let hasTime = false;

    if (parts) {
        const [, year, month, day, hour = '00', minute = '00', second = '00'] = parts;
        hasTime = Boolean(parts[4]) && `${hour}:${minute}:${second}` !== '00:00:00';
        date = new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second));
    } else {
        const parsed = Date.parse(text);
        if (Number.isNaN(parsed)) return text;
        date = new Date(parsed);
        hasTime = date.getHours() !== 0 || date.getMinutes() !== 0 || date.getSeconds() !== 0;
    }

    const shouldIncludeTime = includeTime ?? hasTime;

    return (shouldIncludeTime ? dateTimeFormatter : dateFormatter).format(date);
}
