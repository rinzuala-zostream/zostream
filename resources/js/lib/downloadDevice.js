// iPadOS may identify as a Mac; touch support distinguishes that case.
export function detectDownloadDevice({ userAgent = '', platform = '', maxTouchPoints = 0, userAgentData } = {}) {
    if (/smart-tv|smarttv|hbbtv|tizen|webos|web0s|googletv|android tv|aft\w+/i.test(userAgent)) return 'tv';
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

export function macInstallStep(url) {
    let path = '';
    try { path = new URL(url).pathname.toLowerCase(); } catch { /* No published installer yet. */ }
    if (path.endsWith('.pkg')) return 'PKG file hawng la, installer-in a hrilh angin install rawh.';
    if (path.endsWith('.zip')) return 'ZIP file extract la, Zo Stream app chu Applications-ah dah rawh.';
    return 'DMG file hawng la, Zo Stream chu Applications-ah drag rawh.';
}
