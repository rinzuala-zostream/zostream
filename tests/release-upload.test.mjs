import test from 'node:test';
import assert from 'node:assert/strict';
import { releaseFileError, releaseUploadExtensions, releaseUploadMaxBytes } from '../resources/js/admin/lib/releases.js';

test('Mac and Windows installer pickers accept their own file formats', () => {
    for (const [platform, extensions] of Object.entries(releaseUploadExtensions)) {
        for (const extension of extensions) {
            assert.equal(releaseFileError(platform, { name: `ZoStream.${extension.toUpperCase()}`, size: 1024 }), '');
        }
    }
    assert.notEqual(releaseFileError('windows_update', { name: 'app.dmg', size: 1024 }), '');
    assert.notEqual(releaseFileError('macos_update', { name: 'app.exe', size: 1024 }), '');
    assert.notEqual(releaseFileError('windows_update', { name: 'app.exe.php', size: 1024 }), '');
    assert.notEqual(releaseFileError('update', { name: 'app.apk', size: 1024 }), '');
});

test('missing, empty and oversized installers are rejected before upload', () => {
    assert.notEqual(releaseFileError('macos_update', null), '');
    assert.notEqual(releaseFileError('macos_update', { name: 'app.pkg', size: 0 }), '');
    assert.equal(releaseFileError('macos_update', { name: 'app.pkg', size: releaseUploadMaxBytes }), '');
    assert.notEqual(releaseFileError('macos_update', { name: 'app.pkg', size: releaseUploadMaxBytes + 1 }), '');
});
