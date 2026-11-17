// iPadOS may identify as a Mac; touch support distinguishes that case.
export function detectDownloadDevice({ userAgent = '', platform = '', maxTouchPoints = 0, userAgentData } = {}) {
    if (/smart-tv|smarttv|hbbtv|tizen|webos|web0s|netcast|lg[ ._-]?tv|googletv|android tv|aft\w+/i.test(userAgent)) return 'tv';
    if (/iphone|ipad|ipod/i.test(userAgent) || (/mac/i.test(platform || userAgent) && maxTouchPoints > 1)) return 'ios';
    const identity = `${userAgentData?.platform || ''} ${platform} ${userAgent}`;
    if (/android/i.test(identity)) return 'android';
    if (/windows|win32|win64/i.test(identity)) return 'windows';
    if (/macintosh|macintel|macos|mac os/i.test(identity)) return 'mac';
    return null;
}

export function releaseDownloadUrl(release) {
    if (![true, 1, '1', 'true'].includes(release?.enabled)) return null;
    try {
        const url = new URL(release.url);
        return url.protocol === 'https:' && !url.username && !url.password ? url.href : null;
    } catch { return null; }
}

export function unwrapAppRelease(payload) {
    if (payload?.success === false || payload?.status === false) throw new Error('Release unavailable');
    // V4ResponseEnvelope preserves the legacy controller payload inside data,
    // matching the two-level unwrap used by the Vue admin release editor.
    const response = payload && Object.hasOwn(payload, 'success') ? payload.data : payload;
    if (response?.status === false) throw new Error('Release unavailable');
    return response && Object.hasOwn(response, 'data') ? response.data : response;
}

export function macInstallStep(url) {
    let path = '';
    try { path = new URL(url).pathname.toLowerCase(); } catch { /* No published installer yet. */ }
    if (path.endsWith('.pkg')) return 'Open the PKG file and follow the installer’s instructions.';
    if (path.endsWith('.zip')) return 'Open the ZIP file to extract the app, then move Zo Stream to Applications.';
    if (path.endsWith('.dmg')) return 'Open the DMG file and drag Zo Stream to Applications.';
    return 'Open the downloaded file and follow the installation instructions. For a DMG file, drag Zo Stream to Applications.';
}

export function detectTvRelease(userAgent = '') {
    if (/tizen|samsung/i.test(userAgent)) return 'sam_tv_update';
    if (/webos|web0s|netcast|lg[ ._-]?tv/i.test(userAgent)) return 'lg_tv_update';
    if (/android|googletv|aft\w+/i.test(userAgent)) return 'tv_update';
    return null;
}

export function installerFormat(url) {
    try {
        const extension = new URL(url).pathname.split('.').pop().toLowerCase();
        return ['dmg', 'pkg', 'zip', 'exe', 'msi', 'msix', 'msixbundle', 'appx', 'appxbundle', 'apk', 'ipk', 'wgt'].includes(extension) ? extension.toUpperCase() : null;
    } catch { return null; }
}

export function releaseVersionLabel(release) {
    const value = release?.version;
    if (value == null || value === '') return '';
    // Mobile/TV schemas expose a numeric build code through the version alias.
    return /^\d+$/.test(String(value)) ? `Build ${value}` : `Version ${value}`;
}
