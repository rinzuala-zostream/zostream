import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse } from '@vue/compiler-sfc';
import { computed, ref, watch, nextTick } from 'vue';

const source = parse(readFileSync(new URL('../../resources/js/admin/pages/ThemesPage.vue', import.meta.url), 'utf8')).descriptor.scriptSetup.content;
const setup = new Function('computed', 'ref', 'watch', 'onMounted', 'api', 'window', `${source.replace(/^import .+;\n/gm, '')}\nreturn { form, library, detail, dirty, error, notice, preview, approved, reason, platform, selectTheme, saveDraft, previewDraft, mutateTheme, overrideExpiry, overrideTheme, setOverride, createPreset, toIst, fromIst };`);
const clone = value => JSON.parse(JSON.stringify(value));
const document = {
    priority: 80, platforms: ['web', 'ios'],
    tokens: { background: '#101A16', primary: '#B91C1C', secondary: '#176B48', accent: '#D4AF37' },
    effects: { snowfall: { enabled: true, max_particles: 20 }, animation: { enabled: true, preset: 'twinkle' } },
    assets: [], schedules: [{ start_at: '2026-11-30T18:30:00+00:00', end_at: '2026-12-31T18:30:00+00:00' }],
};

test('Vue theme editor saves reactive data with explicit IST offsets, previews and publishes its saved revision', async () => {
    let stored = { id: 1, key: 'christmas_2026', name: 'Christmas', revision: 1, draft: clone(document), published_version_id: null };
    const calls = [];
    const mounted = [];
    const api = async (path, options = {}) => {
        calls.push({ path, options });
        if (path.startsWith('/admin/themes?')) return { themes: [clone(stored)], templates: { christmas: clone(document) }, active: { theme_key: 'default' }, platforms: ['web', 'ios'], presets: ['twinkle'] };
        if (path.startsWith('/admin/themes/audit')) return { data: [], current_page: 1, last_page: 1 };
        if (path === '/admin/themes/1' && options.method === 'PATCH') {
            assert.equal(options.body.revision, stored.revision);
            assert.equal(options.body.document.schedules[0].start_at, '2026-12-01T00:00:00+05:30');
            assert.equal(options.body.document.tokens.primary, '#112233');
            stored = { ...stored, name: options.body.name, draft: clone(options.body.document), revision: stored.revision + 1 };
            return clone(stored);
        }
        if (path === '/admin/themes/1') return { theme: clone(stored), versions: [], assets: [], audit: [] };
        if (path.endsWith('/preview')) return { preview: { tokens: clone(stored.draft.tokens) }, preview_token: `revision-${stored.revision}`, platform_enabled: true };
        if (path.endsWith('/publish')) {
            assert.equal(options.body.revision, 2);
            assert.equal(options.body.preview_token, 'revision-2');
            assert.equal(options.body.approved, true);
            stored = { ...stored, published_version_id: 10, revision: 3 };
            return clone(stored);
        }
        if (path === '/admin/theme-overrides') return { id: 1 };
        throw Error(`Unexpected API path ${path}`);
    };
    const vm = setup(computed, ref, watch, callback => mounted.push(callback), api, { confirm: () => true });
    await mounted[0]();
    await vm.selectTheme(1);
    assert.equal(vm.form.value.document.schedules[0].start_at, '2026-12-01T00:00');
    vm.form.value.document.tokens.primary = '#112233';
    await nextTick();
    assert.equal(vm.dirty.value, true);
    await vm.saveDraft();
    await nextTick();
    assert.equal(vm.error.value, '');
    assert.equal(vm.dirty.value, false);
    await vm.previewDraft();
    vm.approved.value = true;
    vm.reason.value = 'Christmas launch';
    await vm.mutateTheme('publish');
    assert.equal(vm.error.value, '');
    assert.equal(vm.detail.value.theme.published_version_id, 10);
    assert.equal(calls.filter(call => call.path.endsWith('/publish')).length, 1);
    vm.overrideExpiry.value = '2026-12-01T01:00';
    await vm.setOverride();
    const override = calls.find(call => call.path === '/admin/theme-overrides');
    assert.equal(override.options.body.theme_id, null);
    assert.equal(override.options.body.expires_at, '2026-12-01T01:00:00+05:30');
});

test('theme dates remain IST regardless of the host timezone, and edits invalidate approval', async () => {
    const vm = setup(computed, ref, watch, () => {}, async () => {}, { confirm: () => true });
    assert.equal(vm.toIst('2026-11-30T18:30:00Z'), '2026-12-01T00:00');
    assert.equal(vm.fromIst('2027-01-01T00:00'), '2027-01-01T00:00:00+05:30');
    vm.form.value = { name: 'Christmas', document: clone(document) };
    await nextTick();
    vm.preview.value = { preview_token: 'approved' };
    vm.approved.value = true;
    vm.form.value.document.tokens.accent = '#FFFFFF';
    await nextTick();
    assert.equal(vm.preview.value, null);
    assert.equal(vm.approved.value, false);
});


test('ready-made preset creates a draft without a manually entered key, and old drafts get safe card controls', async () => {
    let created = false;
    const mounted = [];
    const api = async (path, options = {}) => {
        if (path.startsWith('/admin/themes?')) return { themes: [], theme_presets: {}, templates: {}, platforms: ['web'], presets: [], card_presets: ['none', 'snow'] };
        if (path.startsWith('/admin/themes/audit')) return { data: [], current_page: 1, last_page: 1 };
        if (path === '/admin/themes') {
            assert.deepEqual(options.body, { preset_key: 'christmas' });
            created = true;
            return { id: 7 };
        }
        if (path === '/admin/themes/7') return { theme: { id: 7, name: 'Christmas', revision: 1, draft: clone(document) }, versions: [], assets: [], audit: [] };
        throw Error(`Unexpected path ${path}`);
    };
    const vm = setup(computed, ref, watch, callback => mounted.push(callback), api, { confirm: () => true });
    await mounted[0]();
    await vm.createPreset('christmas');
    assert.equal(vm.error.value, '');
    assert.equal(created, true);
    assert.equal(vm.form.value.name, 'Christmas');
    assert.equal(vm.form.value.document.cards.enabled, false);
    vm.form.value.document.cards.enabled = true;
    vm.form.value.document.cards.preset = 'snow';
    assert.equal(vm.dirty.value, true);
});
