import test from 'node:test';
import assert from 'node:assert/strict';
import { detectDownloadDevice, releaseDownloadUrl, macInstallStep } from '../resources/js/lib/downloadDevice.js';

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
