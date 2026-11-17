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
    if (path.endsWith('.pkg')) return 'PKG file hawng la, installer-in a hrilh angin install rawh.';
    if (path.endsWith('.zip')) return 'ZIP file extract la, Zo Stream app chu Applications-ah dah rawh.';
    if (path.endsWith('.dmg')) return 'DMG file hawng la, Zo Stream chu Applications-ah drag rawh.';
    return 'Download file hawng la, installer instructions zawm rawh. DMG a nih chuan app chu Applications-ah drag rawh.';
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
