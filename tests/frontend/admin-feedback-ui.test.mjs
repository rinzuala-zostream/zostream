import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';

// Mount the shared user lookup with real Vue reactivity and a fake API.
const temp = await mkdtemp(join(tmpdir(), 'zostream-feedback-ui-'));
after(() => rm(temp, { recursive: true, force: true }));
const outfile = join(temp, 'resource-ui.mjs');
await build({
    stdin: { contents: `
        export { createRenderer, nextTick, h, reactive } from 'vue';
        export { route, router } from 'vue-router';
        export { default as StatusPanel } from './resources/js/admin/components/StatusPanel.vue';
        export { default as AdminFeedback } from './resources/js/admin/components/AdminFeedback.vue';
        export * from './resources/js/admin/lib/feedback.js';
        export { default as UsersPage } from './resources/js/admin/pages/UsersPage.vue';
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
        builder.onLoad({ filter: /\.vue$/ }, async ({ path }) => {
            const { descriptor } = parse(await readFile(path, 'utf8'), { filename: path });
            return { contents: compileScript(descriptor, { id: path, inlineTemplate: true, templateOptions: { compilerOptions: { hoistStatic: false } } }).content, loader: 'js', resolveDir: resolve(path, '..') };
        });
    } }],
});
const { createRenderer, nextTick, h, reactive, route, StatusPanel, AdminFeedback, UsersPage, notify, toasts, clearToasts, confirmAction, cancelConfirmations } = await import(pathToFileURL(outfile));
globalThis.Document = class {};
globalThis.ShadowRoot = class {};
globalThis.document = Object.assign(new Document(), { activeElement: null });
globalThis.window = { addEventListener() {}, removeEventListener() {}, dispatchEvent() {}, matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }) };
globalThis.localStorage = { getItem: () => null };
globalThis.confirm = () => true;
const node = (tag, text = '') => ({ tag, text, props: {}, style: {}, children: [], parent: null, listeners: {}, tagName: tag.toUpperCase(),
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
test('inline status also produces a global toast that survives page unmount', async () => {
    clearToasts();
    const message = reactive({ text: '' });
    const page = mount({ setup: () => () => h(StatusPanel, { message: message.text, tone: 'success' }) });
    const host = mount(AdminFeedback);
    try {
        message.text = 'User saved successfully.'; await settle();
        assert.match(text(page.root), /User saved successfully/);
        page.app.unmount(); await settle();
        assert.equal(toasts.length, 1);
        assert.match(text(host.root), /Success/); assert.match(text(host.root), /User saved successfully/);
        find(host.root, el => el.tag === 'button' && el.props['aria-label']?.startsWith('Dismiss')).props.onClick();
        await settle(); assert.equal(toasts.length, 0);
    } finally { host.app.unmount(); clearToasts(); }
});

test('dialog focuses Cancel, cancels on Escape, and restores focus without approving', async () => {
    const origin = node('button'); origin.focus();
    const host = mount(AdminFeedback);
    try {
        const result = confirmAction('Delete the selected record?'); await settle();
        const dialog = find(host.root, el => el.tag === 'dialog');
        assert.equal(dialog.open, true);
        assert.equal(text(document.activeElement), 'Cancel');
        dialog.props.onCancel({ preventDefault() {} }); await settle();
        assert.equal(await result, false); assert.equal(dialog.open, false);
        assert.equal(document.activeElement, origin);
        const next = confirmAction('Save changes?'); await settle();
        find(host.root, el => el.tag === 'button' && text(el) === 'Confirm').props.onClick(); await settle();
        assert.equal(await next, true); assert.equal(dialog.open, false);
    } finally { host.app.unmount(); cancelConfirmations(); }
});

test('a real account action waits for the dialog and cancellation never writes to the API', async () => {
    clearToasts(); route.params = {}; route.query = { user: 'viewer' };
    const writes = []; let active = true;
    globalThis.__resourceApi = async (path, options) => {
        if (options.method) { writes.push({ path, ...options }); active = false; }
        return { uid: 'viewer', name: 'Viewer', isACActive: active };
    };
    const page = mount(UsersPage); const host = mount(AdminFeedback);
    try {
        await settle();
        find(page.root, el => el.tag === 'button' && text(el) === 'Suspend user').props.onClick(); await settle();
        assert.equal(writes.length, 0);
        find(host.root, el => el.tag === 'button' && text(el) === 'Cancel').props.onClick(); await settle();
        assert.equal(writes.length, 0);
        find(page.root, el => el.tag === 'button' && text(el) === 'Suspend user').props.onClick(); await settle();
        find(host.root, el => el.tag === 'button' && text(el) === 'Confirm').props.onClick(); await settle();
        assert.deepEqual(writes, [{ path: '/admin/users/viewer', method: 'PUT', body: { isACActive: false } }]);
        assert.match(text(host.root), /User suspended/);
    } finally { page.app.unmount(); host.app.unmount(); clearToasts(); cancelConfirmations(); }
});

test('changing message and tone together emits only the final feedback', async () => {
    const status = reactive({ message: 'Saved.', tone: 'success' });
    const page = mount({ setup: () => () => h(StatusPanel, status) });
    try {
        await settle(); clearToasts();
        status.message = 'Delivery failed.'; status.tone = 'warning'; await settle();
        assert.equal(toasts.length, 1);
        assert.equal(toasts[0].message, 'Delivery failed.'); assert.equal(toasts[0].tone, 'warning');
    } finally { page.app.unmount(); clearToasts(); }
});
