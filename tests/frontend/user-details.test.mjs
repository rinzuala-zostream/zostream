import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';

// Mount the shared user lookup with real Vue reactivity and a fake API.
const temp = await mkdtemp(join(tmpdir(), 'zostream-user-details-'));
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
const viewer = { uid: 'viewer-one', name: 'Zo Viewer', username: 'zoviewer', mail: 'zo@example.com', auth_phone: '9876543210', khua: 'Aizawl', isACActive: true, isAccountComplete: true, is_auth_phone_active: false };
test('Users opens with search and displays the selected account without a table', async () => {
    route.params = {}; route.query = {};
    const calls = [];
    globalThis.__resourceApi = async path => {
        calls.push(path);
        return path.startsWith('/admin/users-search') ? { data: [viewer] } : { data: viewer };
    };
    const { root, app } = mount(UsersPage);
    try {
        await settle(); assert.equal(calls.length, 0);
        assert.match(text(root), /Find a user to get started/);
        const input = find(root, el => el.tag === 'input');
        input.props['onUpdate:modelValue']('zoviewer'); input.props.onInput();
        await new Promise(resolve => setTimeout(resolve, 330)); await settle();
        find(root, el => el.props.role === 'option').props.onClick(); await settle();
        assert.equal(calls.at(-1), '/admin/users/viewer-one');
        assert.match(text(root), /Zo Viewer/); assert.match(text(root), /@zoviewer/);
        assert.match(text(root), /zo@example.com/); assert.match(text(root), /Aizawl/);
        assert.equal(find(root, el => el.tag === 'table'), undefined);
        assert.equal(find(root, el => el.props.role === 'listbox'), undefined);
        assert.equal(input.value, '');
        const edit = find(root, el => el.tag === 'a' && el.props.href?.path === '/manage/users/viewer-one');
        assert.equal(edit.props.href.query.user, viewer.uid);
        const subscriptions = find(root, el => el.tag === 'a' && el.props.href?.path === '/manage/subscriptions');
        assert.equal(subscriptions.props.href.query.user, viewer.uid);
        find(root, el => el.tag === 'button' && text(el) === 'Clear selection').props.onClick(); await settle();
        assert.equal(find(root, el => el.props['aria-label'] === 'Selected user details'), undefined);
    } finally { app.unmount(); }
});

test('Suspend and Delete retain confirmation and affect the selected account only', async () => {
    route.params = {}; route.query = { user: viewer.uid };
    const writes = []; let active = true;
    globalThis.__resourceApi = async (path, options) => {
        if (options.method) { writes.push({ path, ...options }); active = false; }
        return { ...viewer, isACActive: active };
    };
    const { root, app } = mount(UsersPage);
    try {
        await settle(); globalThis.confirm = () => false;
        find(root, el => el.tag === 'button' && text(el) === 'Suspend user').props.onClick(); await settle();
        assert.equal(writes.length, 0);
        globalThis.confirm = () => true;
        find(root, el => el.tag === 'button' && text(el) === 'Suspend user').props.onClick(); await settle();
        assert.deepEqual(writes[0], { path: '/admin/users/viewer-one', method: 'PUT', body: { isACActive: false } });
        assert.match(text(root), /Suspended/);
        find(root, el => el.tag === 'button' && text(el) === 'Delete user').props.onClick(); await settle();
        assert.equal(writes[1].method, 'DELETE'); assert.equal(writes[1].path, '/admin/users/viewer-one');
        assert.equal(find(root, el => el.props['aria-label'] === 'Selected user details'), undefined);
        assert.match(text(root), /User deleted/);
    } finally { app.unmount(); globalThis.confirm = () => true; }
});

test('Save and Cancel return to the selected user and the page reloads the updated details', async () => {
    route.params = { resource: 'users', id: viewer.uid }; route.query = { user: viewer.uid };
    let saved = false;
    globalThis.__resourceApi = async (path, options) => {
        if (options.method === 'PUT') saved = true;
        return { data: { ...viewer, name: saved ? 'Updated Viewer' : viewer.name } };
    };
    let ui = mount(ResourceEditorPage);
    try {
        await settle();
        const cancel = find(ui.root, el => el.tag === 'a' && text(el) === 'Cancel');
        assert.deepEqual(cancel.props.href, { path: '/manage/users', query: { user: viewer.uid } });
        find(ui.root, el => el.tag === 'form').props.onSubmit({ preventDefault() {} }); await settle();
        assert.equal(saved, true); assert.deepEqual(globalThis.__resourceNavigation, cancel.props.href);
    } finally { ui.app.unmount(); }
    route.params = {}; ui = mount(UsersPage);
    try { await settle(); assert.match(text(ui.root), /Updated Viewer/); }
    finally { ui.app.unmount(); }
});

test('a delayed previous account cannot replace the newly selected user', async () => {
    let finishOld;
    route.params = {}; route.query = { user: 'old-viewer' };
    globalThis.__resourceApi = path => path.endsWith('old-viewer')
        ? new Promise(resolve => { finishOld = resolve; }) : Promise.resolve(viewer);
    const { root, app } = mount(UsersPage);
    try {
        await settle(); route.query = { user: viewer.uid }; await settle();
        finishOld({ uid: 'old-viewer', name: 'Old Viewer' }); await settle();
        assert.match(text(root), /Zo Viewer/); assert.doesNotMatch(text(root), /Old Viewer/);
    } finally { app.unmount(); }
});
