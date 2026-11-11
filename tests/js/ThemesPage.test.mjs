import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse } from '@vue/compiler-sfc';
import { computed, ref, watch, nextTick } from 'vue';

const source = parse(readFileSync(new URL('../../resources/js/admin/pages/ThemesPage.vue', import.meta.url), 'utf8')).descriptor.scriptSetup.content;
const setup = new Function('computed', 'ref', 'watch', 'nextTick', 'onMounted', 'onUnmounted', 'api', 'window', `${source.replace(/^import .+;\n/gm, '')}\nreturn { form, detail, library, dirty, error, notice, preview, platform, activePreset, selectTheme, saveSettings, previewTheme, usePreset, useSelected, editPreset, toIst, fromIst };`);
const clone = value => JSON.parse(JSON.stringify(value));
const document = {
    priority: 80, platforms: ['web', 'macos'],
    tokens: { background: '#101A16', primary: '#B91C1C', secondary: '#176B48', accent: '#D4AF37' },
    effects: { snowfall: { enabled: true, max_particles: 20 }, animation: { enabled: true, preset: 'twinkle' } },
    assets: [], schedules: [],
};
async function fixture({ existing = true, activePreset = 'default' } = {}) {
    const themes = existing ? [{ id: 1, preset_key: 'christmas', key: 'christmas_2026', name: 'Christmas', revision: 1, draft: clone(document) }] : [];
    const calls = [], mounted = [];
    let selectedId = null;
    let active = activePreset === 'christmas' ? { theme_key: 'christmas_2026' } : { theme_key: 'default' };
    const api = async (path, options = {}) => {
        calls.push({ path, options: clone(options) });
        if (path.startsWith('/admin/themes?')) return { themes: clone(themes), active, selected_theme_id: selectedId,
            theme_presets: { christmas: { document: { ...clone(document), schedules: [{ start_at: '2026-11-30T18:30:00Z', end_at: '2026-12-31T18:30:00Z' }] } } },
            templates: { christmas: clone(document) }, platforms: ['web', 'macos'], presets: ['twinkle'], card_presets: ['none', 'snow'] };
        if (path.startsWith('/admin/themes/audit')) return { data: [], current_page: 1, last_page: 1 };
        if (path === '/admin/themes') {
            assert.deepEqual(options.body, { preset_key: 'christmas', reuse_preset: true });
            const theme = { id: 1, preset_key: 'christmas', key: 'christmas_2026', name: 'Christmas', revision: 1, draft: clone(document) };
            themes.push(theme); return clone(theme);
        }
        const theme = themes[0];
        if (path === '/admin/themes/1') return { theme: clone(theme), versions: [], assets: [], audit: [] };
        if (path === '/admin/themes/1/settings') {
            assert.equal(options.method, 'PUT');
            assert.equal(options.body.revision, theme.revision);
            theme.name = options.body.name; theme.draft = clone(options.body.document); theme.revision++;
            return clone(theme);
        }
        if (path === '/admin/themes/1/use') {
            assert.deepEqual(options.body, { revision: theme.revision });
            selectedId = theme.id; active = { theme_key: theme.key }; theme.revision++;
            return clone(theme);
        }
        if (path.endsWith('/preview')) return { preview: { tokens: clone(theme.draft.tokens) }, platform_enabled: true };
        throw Error(`Unexpected path ${path}`);
    };
    const vm = setup(computed, ref, watch, nextTick, callback => mounted.push(callback), () => {}, api, { confirm: () => true });
    await mounted[0]();
    return { vm, calls, themes };
}

test('Use applies a preset directly and highlights the active preset without approval or publication', async () => {
    const { vm, calls } = await fixture({ existing: false });
    assert.equal(vm.activePreset.value, 'default');
    await vm.usePreset('christmas');
    assert.equal(vm.error.value, '');
    assert.equal(vm.activePreset.value, 'christmas');
    assert.equal(calls.filter(c => c.path === '/admin/themes').length, 1);
    assert.equal(calls.filter(c => c.path.endsWith('/use')).length, 1);
    assert.equal(calls.some(c => c.path.includes('/publish') || c.path.includes('/preview')), false);
    await vm.usePreset('christmas');
    assert.equal(calls.filter(c => c.path === '/admin/themes').length, 1, 'Existing preset settings must be reused.');
});

test('Add schedule opens an editable IST window and Save changes activates settings in one request', async () => {
    const { vm, calls } = await fixture();
    await vm.editPreset('christmas', true);
    assert.equal(vm.error.value, '');
    assert.equal(vm.form.value.document.schedules[0].start_at, '2026-12-01T00:00');
    assert.equal(vm.dirty.value, true);
    vm.form.value.document.tokens.primary = '#112233';
    await nextTick();
    await vm.saveSettings();
    assert.equal(vm.error.value, '');
    assert.equal(vm.dirty.value, false);
    const save = calls.find(c => c.path.endsWith('/settings'));
    assert.equal(save.options.body.document.schedules[0].start_at, '2026-12-01T00:00:00+05:30');
    assert.equal(save.options.body.document.tokens.primary, '#112233');
    assert.equal(calls.some(c => c.path.endsWith('/use') || c.path.endsWith('/publish')), false);
});

test('Use this theme saves edits first and preserves server-derived active highlighting', async () => {
    const { vm, calls } = await fixture({ activePreset: 'christmas' });
    await vm.selectTheme(1);
    vm.form.value.document.cards.enabled = true;
    vm.form.value.document.cards.preset = 'snow';
    await nextTick();
    await vm.useSelected();
    assert.equal(vm.error.value, '');
    assert.equal(vm.dirty.value, false);
    assert.equal(vm.activePreset.value, 'christmas');
    const mutations = calls.filter(c => c.options.method);
    assert.deepEqual(mutations.map(c => c.path), ['/admin/themes/1/settings', '/admin/themes/1/use']);
    assert.equal(mutations[1].options.body.revision, 2);
});

test('theme dates remain explicitly IST and editing invalidates only the optional preview', async () => {
    const { vm } = await fixture();
    await vm.selectTheme(1);
    assert.equal(vm.toIst('2026-11-30T18:30:00Z'), '2026-12-01T00:00');
    assert.equal(vm.fromIst('2027-01-01T00:00'), '2027-01-01T00:00:00+05:30');
    await vm.previewTheme();
    vm.form.value.name = 'My Christmas';
    await nextTick();
    assert.equal(vm.preview.value, null);
    assert.equal(vm.activePreset.value, 'default', 'Editing alone must not claim that a theme is active.');
});
