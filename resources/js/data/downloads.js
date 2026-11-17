// Keys match the existing App releases form and public app-releases API.
export const downloadPlatforms = [
    { key: 'mac', name: 'Mac', icon: 'desktop', releaseKey: 'macos_update', note: 'macOS 14 or later' },
    { key: 'windows', name: 'Windows', icon: 'windows', releaseKey: 'windows_update', note: 'Windows PC' },
    { key: 'android', name: 'Android', icon: 'phone', releaseKey: 'update', note: 'Phone & tablet' },
    { key: 'ios', name: 'iPhone & iPad', icon: 'apple', releaseKey: 'ios_update', note: 'iOS & iPadOS' },
    { key: 'tv', name: 'Smart TV', icon: 'tv', note: 'Android TV, Samsung & LG' },
];

export const tvPlatforms = [
    { key: 'tv_update', name: 'Android TV' },
    { key: 'sam_tv_update', name: 'Samsung TV' },
    { key: 'lg_tv_update', name: 'LG TV' },
];
