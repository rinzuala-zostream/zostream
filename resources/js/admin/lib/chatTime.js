const timeFormatter = new Intl.DateTimeFormat('en-IN', {
    hour: 'numeric',
    minute: '2-digit',
});
const dayFormatter = new Intl.DateTimeFormat('en-IN', {
    day: 'numeric',
    month: 'short',
});
const dateFormatter = new Intl.DateTimeFormat('en-IN', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

function sameDay(left, right) {
    return left.getFullYear() === right.getFullYear()
        && left.getMonth() === right.getMonth()
        && left.getDate() === right.getDate();
}

export function formatChatTime(value) {
    if (!value) return '';
    const messageDate = new Date(value);
    if (Number.isNaN(messageDate.getTime())) return '';

    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);
    const time = timeFormatter.format(messageDate);

    if (sameDay(messageDate, today)) return `Today · ${time}`;
    if (sameDay(messageDate, yesterday)) return `Yesterday · ${time}`;
    if (messageDate.getFullYear() === today.getFullYear()) return `${dayFormatter.format(messageDate)} · ${time}`;
    return `${dateFormatter.format(messageDate)} · ${time}`;
}
