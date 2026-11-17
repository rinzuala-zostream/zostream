const field = (name, label, type = 'text', extra = {}) => ({ name, label, type, ...extra });
export const channelFields = [
    field('user_id', 'Channel owner UID', 'user', { required: true }),
    field('name', 'Channel name', 'text', { required: true, max: 255 }),
    field('slug', 'Slug', 'text', { max: 255 }),
    field('status', 'Status', 'select', { options: ['pending', 'active', 'suspended', 'deleted'], default: 'pending' }),
    field('description', 'Description', 'textarea'),
    field('logo', 'Logo URL', 'url', { max: 500 }),
    field('banner', 'Banner URL', 'url', { max: 500 }),
    field('is_verified', 'Verified channel', 'checkbox'),
];
export const planFields = [
    field('name', 'Plan name', 'text', { max: 100 }),
    field('duration_days', 'Duration (days)', 'number', { required: true, min: 1, default: 30 }),
    field('price', 'Price (INR)', 'number', { required: true, min: 0, step: '0.01' }),
    field('discount_percent', 'Plan discount (%)', 'number', { min: 0, max: 100, step: '0.01', default: 0 }),
    field('is_active', 'Active plan', 'checkbox', { default: true }),
];
export const contentFields = [
    field('title', 'Title', 'text', { max: 255 }),
    field('content_type', 'Content type', 'select', { required: true, options: ['video', 'audio', 'live', 'podcast', 'article'], default: 'video' }),
    field('access_type', 'Access', 'select', { options: ['free', 'subscriber_only', 'ppv'], default: 'subscriber_only' }),
    field('status', 'Visibility', 'select', { options: ['draft', 'published', 'private'], default: 'draft' }),
    field('description', 'Description', 'textarea'),
    field('thumbnail', 'Thumbnail URL', 'url', { max: 500 }),
    field('duration', 'Duration (seconds)', 'number', { min: 0, default: 0 }),
    field('release_date', 'Release date', 'datetime-local'),
];
export const mediaFields = [
    field('media_type', 'Media type', 'select', { options: ['video', 'audio', 'subtitle', 'thumbnail'], default: 'video' }),
    field('url', 'Media URL', 'url', { required: true }),
    field('quality', 'Quality', 'text', { max: 50 }),
    field('language', 'Language', 'text', { max: 20 }),
    field('file_size', 'File size (bytes)', 'number', { min: 0 }),
];
export const ppvFields = [
    field('price', 'Rental price (INR)', 'number', { required: true, min: 0, step: '0.01' }),
    field('rental_days', 'Rental duration (days)', 'number', { min: 1, default: 7 }),
    field('is_active', 'Active rental settings', 'checkbox', { default: true }),
];

export function formValues(fields, record = {}) {
    return Object.fromEntries(fields.map(f => {
        let value = record[f.name] ?? f.default ?? (f.type === 'checkbox' ? false : '');
        if (f.type === 'datetime-local' && value) {
            const date = new Date(value);
            if (!Number.isNaN(date.getTime())) {
                const pad = n => String(n).padStart(2, '0');
                value = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
            }
        }
        return [f.name, value];
    }));
}

export function formPayload(fields, values) {
    return Object.fromEntries(fields.filter(f => f.name !== 'slug' || String(values[f.name] || '').trim()).map(f => {
        let value = values[f.name];
        if (f.type === 'checkbox') value = Boolean(value);
        else if (value === '' || value == null) value = f.default ?? null;
        else if (f.type === 'datetime-local') value = new Date(value).toISOString();
        else if (f.type === 'number') value = Number(value);
        else value = String(value).trim();
        return [f.name, value];
    }));
}
export const money = value => value == null ? '—' : new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(Number(value));

export function formatBytes(value) {
    if (value == null) return '—';
    const bytes = Number(value);
    if (!Number.isFinite(bytes) || bytes < 0) return '—';
    if (bytes < 1024) return `${bytes} B`;
    const unit = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), 3);
    return `${(bytes / 1024 ** unit).toFixed(1)} ${['B', 'KB', 'MB', 'GB'][unit]}`;
}
