import test from 'node:test';
import assert from 'node:assert/strict';
import { detectDownloadDevice, detectTvRelease, installerFormat, releaseDownloadUrl, macInstallStep, releaseVersionLabel, unwrapAppRelease } from '../resources/js/lib/downloadDevice.js';
import { downloadPlatforms, tvPlatforms } from '../resources/js/data/downloads.js';

test('detects desktop and mobile operating systems without guessing unsupported devices', () => {
    const devices = [
        [{ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', platform: 'MacIntel' }, 'mac'],
        [{ userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', platform: 'Win32' }, 'windows'],
        [{ userAgent: 'Mozilla/5.0 (X11; Linux x86_64)', userAgentData: { platform: 'Windows' } }, 'windows'],
        [{ userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8)' }, 'android'],
        [{ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)' }, 'ios'],
        [{ userAgent: 'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X)' }, 'ios'],
        [{ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', platform: 'MacIntel', maxTouchPoints: 5 }, 'ios'],
        [{ userAgent: 'Mozilla/5.0 (X11; Linux x86_64)' }, null],
        [{ userAgent: 'Mozilla/5.0 (X11; CrOS x86_64)' }, null],
        [{}, null],
    ];
    for (const [navigator, expected] of devices) assert.equal(detectDownloadDevice(navigator), expected);
});

test('TV devices take precedence over their underlying Android or Linux OS', () => {
    for (const userAgent of ['SMART-TV; Tizen 8.0', 'Linux; Android 12; Android TV', 'Linux; Android 9; AFTMM', 'Web0S; Linux', 'HbbTV/1.5.1']) {
        assert.equal(detectDownloadDevice({ userAgent }), 'tv');
    }
});

test('desktop downloads require an enabled release with a safe HTTPS link', () => {
    for (const enabled of [true, 1, '1', 'true']) assert.equal(releaseDownloadUrl({ enabled, url: 'https://example.com/app.dmg' }), 'https://example.com/app.dmg');
    for (const enabled of [false, 0, 'false', undefined]) assert.equal(releaseDownloadUrl({ enabled, url: 'https://example.com/app.dmg' }), null);
    for (const url of ['javascript:alert(1)', 'http://example.com/app.pkg', '//example.com/app.pkg', 'https://user:secret@example.com/app.pkg', '', null]) {
        assert.equal(releaseDownloadUrl({ enabled: true, url }), null);
    }
    assert.equal(releaseDownloadUrl(null), null);
});

test('Mac instructions match the published installer format even with query parameters', () => {
    assert.match(macInstallStep('https://example.com/ZoStream.PKG?download=1'), /PKG.*installer/);
    assert.match(macInstallStep('https://example.com/ZoStream.zip'), /ZIP.*extract/);
    assert.match(macInstallStep('https://example.com/ZoStream.dmg'), /DMG.*drag/);
    assert.match(macInstallStep(null), /Applications/);
});

test('each app release managed by admin has a download destination', () => {
    const keys = [...downloadPlatforms.map(platform => platform.releaseKey).filter(Boolean), ...tvPlatforms.map(platform => platform.key)];
    assert.deepEqual(keys.sort(), ['ios_update', 'lg_tv_update', 'macos_update', 'sam_tv_update', 'tv_update', 'update', 'windows_update']);
});

test('TV detection selects the matching vendor without guessing an unknown TV family', () => {
    assert.equal(detectTvRelease('SMART-TV; Tizen 8.0'), 'sam_tv_update');
    assert.equal(detectTvRelease('Web0S; Linux'), 'lg_tv_update');
    assert.equal(detectDownloadDevice({ userAgent: 'NetCast LG TV' }), 'tv');
    assert.equal(detectTvRelease('NetCast LG TV'), 'lg_tv_update');
    assert.equal(detectTvRelease('Linux; Android 12; Android TV'), 'tv_update');
    assert.equal(detectTvRelease('Linux; Android 9; AFTMM'), 'tv_update');
    assert.equal(detectTvRelease('HbbTV/1.5.1'), null);
});

test('release details distinguish numeric build codes from version names and actual file formats', () => {
    assert.equal(releaseVersionLabel({ version: '1.2.3' }), 'Version 1.2.3');
    assert.equal(releaseVersionLabel({ version: 215 }), 'Build 215');
    assert.equal(releaseVersionLabel({ version: '215' }), 'Build 215');
    assert.equal(releaseVersionLabel({ version: '' }), '');
    assert.equal(releaseVersionLabel(null), '');
    assert.equal(installerFormat('https://example.com/ZoStream.MSIXBUNDLE?key=1'), 'MSIXBUNDLE');
    assert.equal(installerFormat('https://example.com/ZoStream.apk?key=1'), 'APK');
    assert.equal(installerFormat('https://example.com/store'), null);
    assert.equal(installerFormat(null), null);
});

test('reads the actual V4 bridge envelope as well as native V4 and legacy controller responses', () => {
    const release = { platform: 'macos_update', enabled: true, force: false, version: '1.2.3', url: 'https://example.com/app.dmg' };
    const legacy = { status: true, data: release };
    const bridged = { success: true, data: legacy, message: null, meta: { api_version: '4' }, error: null };
    for (const payload of [bridged, legacy, { success: true, data: release }, release]) {
        assert.deepEqual(unwrapAppRelease(payload), release);
        assert.equal(releaseDownloadUrl(unwrapAppRelease(payload)), release.url);
    }
    for (const payload of [{ success: false }, { status: false }, { success: true, data: { status: false, message: 'Unavailable' } }]) {
        assert.throws(() => unwrapAppRelease(payload), /Release unavailable/);
    }
});
