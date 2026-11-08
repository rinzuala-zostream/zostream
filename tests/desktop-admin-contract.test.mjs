import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { deviceTypes, desktopPlatforms, analyticsPlatforms, deviceTypeLabel, platformLabel } from '../resources/js/admin/lib/platforms.js';
import { resources, normalizeForSubmit } from '../resources/js/admin/lib/resources.js';

test('billing categories include desktop while Mac/Windows remain OS platforms', () => {
    assert.deepEqual(deviceTypes.map(item => item.value).sort(), ['browser', 'desktop', 'mobile', 'tv']);
    assert.deepEqual(desktopPlatforms.map(item => item.value).sort(), ['macos', 'windows']);
    assert.ok(deviceTypeLabel('desktop').includes('Mac / Windows'));
    assert.equal(platformLabel('macos'), 'Mac (macOS)');
    for (const platform of ['ios', 'android', 'tv', 'web', 'macos', 'windows']) assert.ok(analyticsPlatforms.some(item => item.value === platform));
});

test('plan and device editors keep desktop and never overwrite app-reported OS', () => {
    for (const key of ['plans', 'devices']) assert.ok(resources[key].fields.find(field => field.name === 'device_type').options.includes('desktop'));
    assert.ok(resources.devices.columns.includes('platform'));
    const submitted = normalizeForSubmit('devices', { device_name: 'My Mac', device_type: 'desktop', platform: 'macos', status: 'active' });
    assert.equal(submitted.device_type, 'desktop');
    assert.equal('platform' in submitted, false);
});

test('desktop Firebase release metadata is public read-only like existing releases', async () => {
    const { rules } = JSON.parse(await readFile(new URL('../Library/firebase/database.rules.json', import.meta.url), 'utf8'));
    assert.equal(rules['.write'], false);
    for (const key of ['update', 'ios_update', 'tv_update', 'windows_update', 'macos_update']) {
        assert.equal(rules[key]['.read'], true);
        assert.notEqual(rules[key]['.write'], true);
    }
});
