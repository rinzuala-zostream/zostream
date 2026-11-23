const field = (name, label, type = 'text', extra = {}) => ({ name, label, type, ...extra });
export const userEditSections = [
    { title: 'Identity', fields: [field('num', 'Internal ID', 'text', { readonly: true }), field('uid', 'User ID', 'text', { readonly: true })] },
    { title: 'Profile', fields: [field('name', 'Name', 'text', { max: 180 }), field('username', 'Username', 'text', { max: 30 }), field('dob', 'Date of birth', 'date'), field('profile_gender', 'Gender', 'select', { options: [['male', 'Male'], ['female', 'Female'], ['other', 'Other'], ['prefer_not_to_say', 'Prefer not to say']] }), field('img', 'Profile image URL', 'text', { max: 2048, wide: true })] },
    { title: 'Contact', fields: [field('mail', 'Email', 'text', { max: 180 }), field('country_code', 'Country code', 'text', { max: 10 }), field('auth_phone', 'Phone', 'text', { max: 40 }), field('call', 'Alternate phone', 'text', { max: 40 })] },
    { title: 'Location', fields: [field('profile_district_code', 'Profile district', 'district'), field('profile_village_code', 'Profile village / town', 'village'), field('khua', 'Town', 'text', { max: 180 }), field('veng', 'Locality', 'text', { max: 180 })] },
    { title: 'Account access', fields: [field('isACActive', 'Account active', 'checkbox'), field('isAccountComplete', 'Account complete', 'checkbox'), field('is_auth_phone_active', 'Phone verified', 'checkbox')] },
    { title: 'Device & notifications', fields: [field('device_name', 'Device name', 'text', { max: 255 }), field('device_id', 'Device ID', 'text', { max: 255 }), field('token', 'FCM notification token', 'textarea', { max: 2048, wide: true })] },
    { title: 'Account dates', fields: [field('created_date', 'Created date', 'datetime-local'), field('edit_date', 'Last updated', 'datetime-local'), field('lastLogin', 'Last login', 'datetime-local')] },
];
export function userFieldValue(field, value) {
    if (field.type === 'checkbox') return value == null ? null : value === true || value === 1 || value === '1';
    if (value == null) return '';
    if (field.type === 'date' || field.type === 'datetime-local') {
        const match = String(value).match(/^(\d{4}-\d{2}-\d{2})(?:[T\s](\d{2}:\d{2}(?::\d{2})?))?/);
        if (match) return field.type === 'date' ? match[1] : `${match[1]}T${match[2] || '00:00:00'}`;
        const parsed = new Date(value);
        if (!Number.isNaN(parsed.getTime())) {
            const pad = number => String(number).padStart(2, '0');
            const date = `${parsed.getFullYear()}-${pad(parsed.getMonth() + 1)}-${pad(parsed.getDate())}`;
            return field.type === 'date' ? date : `${date}T${pad(parsed.getHours())}:${pad(parsed.getMinutes())}:${pad(parsed.getSeconds())}`;
        }
    }
    return String(value);
}
export function userChanges(draft, original) {
    const changes = {};
    for (const field of userEditSections.flatMap(section => section.fields)) {
        if (field.readonly || draft[field.name] === original[field.name]) continue;
        changes[field.name] = draft[field.name] === '' ? null : draft[field.name];
    }
    return changes;
}
