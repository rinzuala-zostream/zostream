// Desktop is one billing/device category; the operating system is separate.
export const deviceTypes = [
    { value: 'mobile', label: 'Mobile' },
    { value: 'browser', label: 'Browser' },
    { value: 'tv', label: 'TV' },
    { value: 'desktop', label: 'Desktop (Mac / Windows)' },
];

export const desktopPlatforms = [
    { value: 'macos', label: 'Mac (macOS)' },
    { value: 'windows', label: 'Windows' },
];

export const analyticsPlatforms = [
    { value: 'ios', label: 'iOS' },
    { value: 'tvos', label: 'Apple TV' },
    { value: 'android', label: 'Android Mobile' },
    { value: 'tv', label: 'Android TV' },
    { value: 'tizen', label: 'Tizen OS' },
    { value: 'webos', label: 'LG webOS' },
    { value: 'web', label: 'Web Browser' },
    ...desktopPlatforms,
];

export const deviceTypeLabel = value => deviceTypes.find(item => item.value === String(value || '').toLowerCase())?.label || String(value || 'Unknown');
export const platformLabel = value => analyticsPlatforms.find(item => item.value === String(value || '').toLowerCase())?.label || String(value || 'Unknown');
export const optionLabel = (field, value) => field.name === 'device_type' ? deviceTypeLabel(value) : field.name === 'platform' ? platformLabel(value) : value;
