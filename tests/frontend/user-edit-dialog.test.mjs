import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';

// Mount the shared user lookup with real Vue reactivity and a fake API.
const temp = await mkdtemp(join(tmpdir(), 'zostream-user-editor-'));
after(() => rm(temp, { recursive: true, force: true }));
const outfile = join(temp, 'resource-ui.mjs');
await build({
    stdin: { contents: `
        export { createRenderer, nextTick, h, reactive } from 'vue';
        export { route, router } from 'vue-router';
        export { default as UsersPage } from './resources/js/admin/pages/UsersPage.vue';
        export { default as ResourceEditorPage } from './resources/js/admin/pages/ResourceEditorPage.vue';
    `, resolveDir: resolve('.'), loader: 'js' },
    outfile, bundle: true, platform: 'node', format: 'esm', logLevel: 'silent',
    plugins: [{ name: 'resource-vue-test', setup(builder) {
        builder.onResolve({ filter: /^vue-router$/ }, () => ({ path: 'router', namespace: 'resource-test' }));
        builder.onLoad({ filter: /.*/, namespace: 'resource-test' }, () => ({ contents: `
            import { reactive } from 'vue';
            export const route = reactive({ params: { id: '1' }, query: {} });
            export const router = { push: async path => { globalThis.__resourceNavigation = path; }, replace: async location => { route.query = location.query; } };
            export const useRoute = () => route;
            export const useRouter = () => router;
            export const onBeforeRouteLeave = () => {};
            export const onBeforeRouteUpdate = () => {};
        `, resolveDir: resolve('.') }));
        builder.onLoad({ filter: /[/\\]admin[/\\]lib[/\\]api\.js$/ }, () => ({ contents: `
            export const api = (path, options = {}) => globalThis.__resourceApi(path, options);
            export const apiBlob = path => globalThis.__resourceApi(path, {});
            export const queryString = values => '?' + new URLSearchParams(Object.entries(values).filter(([, value]) => value !== '' && value != null));
        ` }));
        builder.onLoad({ filter: /[/\\]admin[/\\]lib[/\\]feedback\.js$/ }, () => ({ contents: `
            export const notify = () => {};
            export const confirmAction = async message => globalThis.confirm(message);
        ` }));
        builder.onLoad({ filter: /\.vue$/ }, async ({ path }) => {
            const { descriptor } = parse(await readFile(path, 'utf8'), { filename: path });
            return { contents: compileScript(descriptor, { id: path, inlineTemplate: true, templateOptions: { compilerOptions: { hoistStatic: false } } }).content, loader: 'js', resolveDir: resolve(path, '..') };
        });
    } }],
});
const { createRenderer, nextTick, h, reactive, route, UsersPage, ResourceEditorPage } = await import(pathToFileURL(outfile));
globalThis.Document = class {};
globalThis.ShadowRoot = class {};
globalThis.document = Object.assign(new Document(), { activeElement: null });
globalThis.window = { addEventListener() {}, removeEventListener() {}, dispatchEvent() {} };
globalThis.confirm = () => true;
const node = (tag, text = '') => ({ tag, text, props: {}, style: {}, children: [], parent: null, listeners: {}, tagName: tag.toUpperCase(),
    querySelector(selector) { return find(this, el => el.props['aria-invalid'] === true); },
    isConnected: true, open: false, showModal() { this.open = true; }, close() { this.open = false; }, focus() { globalThis.document.activeElement = this; },
    getRootNode() { return globalThis.document; },
    addEventListener(name, callback) { this.listeners[name] = callback; }, removeEventListener() {},
    get options() { return this.children.filter(child => child.tag === 'option'); },
});
const teleportTarget = node('body');
const renderer = createRenderer({
    querySelector: () => teleportTarget,
    createElement: tag => node(tag), createText: text => node('#text', text), createComment: text => node('#comment', text),
    setText: (el, text) => { el.text = text; }, setElementText: (el, text) => { el.text = text; el.children = []; },
    patchProp: (el, key, previous, value) => { el.props[key] = value; el[key] = value; },
    parentNode: el => el.parent, nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] || null,
    insert(el, parent, anchor = null) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); el.parent = parent; const i = parent.children.indexOf(anchor); parent.children.splice(i < 0 ? parent.children.length : i, 0, el); },
    remove(el) { if (el.parent) { el.parent.children.splice(el.parent.children.indexOf(el), 1); el.parent = null; } },
});
function mount(component) {
    const root = node('root'); const app = renderer.createApp(component);
    app.component('RouterLink', { props: ['to'], setup: (props, { slots }) => () => h('a', { href: props.to }, slots.default?.()) });
    app.mount(root); return { root, app };
}
const text = el => el.tag === '#comment' ? '' : el.text + el.children.map(text).join(' ');
function find(root, predicate) { if (predicate(root)) return root; for (const child of root.children) { const found = find(child, predicate); if (found) return found; } }
async function settle() { for (let i = 0; i < 4; i++) { await nextTick(); await new Promise(setImmediate); } }
const viewer = {
    num: 7, uid: 'viewer', name: 'Original Viewer', username: 'original_viewer',
    profile_gender: 'female', profile_district_code: '261', profile_village_code: 'capital-261',
    token: 'existing-fcm', isACActive: true, isAccountComplete: null, is_auth_phone_active: false,
    lastLogin: '2026-01-01 12:13:14', created_date: '2025-01-01 10:11:12',
};
const districts = [{ name: 'Aizawl', lgd_code: '261', villages: [{ name: 'Aizawl', lgd_code: 'capital-261' }] }, { name: 'Champhai', lgd_code: '262', villages: [{ name: 'Champhai', lgd_code: 'capital-262' }] }];
function field(root, label) {
    const parent = find(root, el => el.tag === 'label' && el.children.some(child => child.tag === 'span' && text(child) === label));
    assert.ok(parent, `Missing field ${label}`);
    return find(parent, el => ['input', 'select', 'textarea'].includes(el.tag));
}
async function open() {
    route.params = {}; route.query = { user: 'viewer' };
    const page = mount(UsersPage); await settle();
    find(page.root, el => el.tag === 'button' && text(el).trim() === 'Edit user').props.onClick(); await settle();
    return page;
}
function submit(root) { find(root, el => el.tag === 'form').props.onSubmit({ preventDefault() {} }); }

test('Edit opens a full dialog and saves only changed values without resetting existing data', async () => {
    const writes = [];
    globalThis.__resourceApi = async (path, options) => {
        if (path.endsWith('profile-locations')) return { districts };
        if (options.method === 'PATCH') { writes.push(options.body); return { ...viewer, ...options.body }; }
        return viewer;
    };
    const { root, app } = await open();
    try {
        const dialog = find(root, el => el.tag === 'dialog'); assert.equal(dialog.open, true);
        assert.equal(field(dialog, 'Internal ID').props.readonly, true); assert.equal(field(dialog, 'User ID').props.readonly, true);
        for (const label of ['Username', 'Gender', 'Profile district', 'Profile village / town', 'FCM notification token', 'Created date', 'Last updated', 'Last login', 'Phone verified']) assert.ok(field(dialog, label));
        field(dialog, 'Name').props['onUpdate:modelValue']('Updated Viewer'); await settle();
        submit(dialog); await settle();
        assert.deepEqual(writes, [{ name: 'Updated Viewer' }]);
        assert.equal(find(root, el => el.tag === 'dialog'), undefined);
        assert.match(text(root), /Updated Viewer/); assert.equal(route.query.user, 'viewer');
    } finally { app.unmount(); }
});

test('profile, location, dates and account flags are editable and Cancel makes no write', async () => {
    const writes = [];
    globalThis.__resourceApi = async (path, options) => {
        if (path.endsWith('profile-locations')) return { districts };
        if (options.method === 'PATCH') { writes.push(options.body); return { ...viewer, ...options.body }; }
        return viewer;
    };
    const { root, app } = await open();
    try {
        let dialog = find(root, el => el.tag === 'dialog');
        field(dialog, 'Name').props['onUpdate:modelValue']('Discard me');
        find(dialog, el => el.tag === 'button' && text(el) === 'Cancel').props.onClick(); await settle();
        assert.equal(writes.length, 0); assert.doesNotMatch(text(root), /Discard me/);
        find(root, el => el.tag === 'button' && text(el).trim() === 'Edit user').props.onClick(); await settle();
        dialog = find(root, el => el.tag === 'dialog');
        field(dialog, 'Username').props['onUpdate:modelValue']('updated_viewer');
        field(dialog, 'Gender').props['onUpdate:modelValue']('other');
        const district = field(dialog, 'Profile district'); district.props['onUpdate:modelValue']('262'); district.props.onChange(); await settle();
        assert.match(text(field(dialog, 'Profile village / town')), /Champhai/);
        field(dialog, 'Profile village / town').props['onUpdate:modelValue']('capital-262');
        field(dialog, 'Last login').props['onUpdate:modelValue']('2026-02-02T13:14:15');
        field(dialog, 'Account active').props['onUpdate:modelValue'](false);
        await settle(); submit(dialog); await settle();
        assert.deepEqual(writes[0], { username: 'updated_viewer', profile_gender: 'other', profile_district_code: '262', profile_village_code: 'capital-262', isACActive: false, lastLogin: '2026-02-02T13:14:15' });
        assert.match(text(root), /Suspended/);
    } finally { app.unmount(); }
});

test('failed saves keep the editor open and preserve the draft for correction', async () => {
    globalThis.__resourceApi = async (path, options) => {
        if (path.endsWith('profile-locations')) return { districts };
        if (options.method === 'PATCH') throw Object.assign(new Error('Username is taken.'), { details: { username: ['Choose another username.'] } });
        return viewer;
    };
    const { root, app } = await open();
    try {
        let dialog = find(root, el => el.tag === 'dialog');
        field(dialog, 'Username').props['onUpdate:modelValue']('taken'); await settle(); submit(dialog); await settle();
        dialog = find(root, el => el.tag === 'dialog'); assert.equal(dialog.open, true);
        assert.equal(field(dialog, 'Username').value, 'taken');
        assert.match(text(dialog), /Choose another username/);
        dialog.props.onCancel({ preventDefault() {} }); await settle();
        assert.equal(find(root, el => el.tag === 'dialog'), undefined);
        assert.equal(route.query.user, 'viewer');
    } finally { app.unmount(); }
});

test('a direct edit link opens the dialog after loading the selected account', async () => {
    route.params = {}; route.query = { user: 'viewer', edit: '1' };
    globalThis.__resourceApi = async path => path.endsWith('profile-locations') ? { districts } : viewer;
    const { root, app } = mount(UsersPage);
    try { await settle(); assert.equal(find(root, el => el.tag === 'dialog').open, true); }
    finally { app.unmount(); }
});
