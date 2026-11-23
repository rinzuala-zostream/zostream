import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';

// Mount the shared user lookup with real Vue reactivity and a fake API.
const temp = await mkdtemp(join(tmpdir(), 'zostream-subscriber-details-'));
after(() => rm(temp, { recursive: true, force: true }));
const outfile = join(temp, 'resource-ui.mjs');
await build({
    stdin: { contents: `
        export { createRenderer, nextTick, h, reactive } from 'vue';
        export { route, router } from 'vue-router';
        export { default as SubscriptionsPage } from './resources/js/admin/pages/SubscriptionsPage.vue';
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
const { createRenderer, nextTick, h, reactive, route, SubscriptionsPage } = await import(pathToFileURL(outfile));
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
test('subscriber landing starts with search and renders selected user details without a table', async () => {
    route.params = {}; route.query = {};
    const calls = [];
    const user = { uid: 'viewer-one', name: 'Zo Viewer', username: 'zoviewer', mail: 'zo@example.com', auth_phone: '9876543210' };
    globalThis.__resourceApi = async path => {
        calls.push(path);
        if (path.startsWith('/admin/users-search')) return { data: [user] };
        assert.match(path, /user_id=viewer-one/);
        return { data: { data: [{ id: 7, user_id: user.uid, plan_id: 3, plan: { name: 'Mobile monthly', device_type: 'mobile' }, start_at: '2026-01-01', end_at: '2027-01-01', is_active: true, devices: [] }], total: 1, current_page: 1, last_page: 1 } };
    };
    const { root, app } = mount(SubscriptionsPage);
    try {
        await settle(); assert.equal(calls.length, 0);
        assert.match(text(root), /Find a subscriber to get started/);
        const field = find(root, el => el.tag === 'input');
        field.props['onUpdate:modelValue']('zoviewer'); field.props.onInput();
        await new Promise(resolve => setTimeout(resolve, 330)); await settle();
        find(root, el => el.props.role === 'option').props.onClick(); await settle();
        const details = find(root, el => el.props['aria-label'] === 'Selected subscriber details');
        assert.match(text(details), /Zo Viewer/); assert.match(text(details), /@zoviewer/);
        assert.match(text(details), /9876543210/); assert.match(text(details), /zo@example.com/);
        assert.match(text(root), /Mobile monthly/); assert.equal(find(root, el => el.tag === 'table'), undefined);
        assert.equal(find(root, el => el.props.role === 'listbox'), undefined);
        assert.equal(field.value, '');
        const edit = find(root, el => el.tag === 'a' && el.props.href?.path === '/manage/subscriptions/7');
        assert.equal(edit.props.href.query.user, 'viewer-one');
        find(root, el => el.tag === 'button' && text(el) === 'Clear selection').props.onClick(); await settle();
        assert.equal(find(root, el => el.props['aria-label'] === 'Selected subscriber details'), undefined);
    } finally { app.unmount(); }
});

test('returning from edit restores the selected subscriber and shows inactive access', async () => {
    route.params = {}; route.query = { user: 'returning-viewer' };
    globalThis.__resourceApi = async path => path.startsWith('/admin/users/')
        ? { uid: 'returning-viewer', name: 'Returning Viewer' }
        : { data: { data: [{ id: 8, plan: { name: 'Expired desktop', device_type: 'desktop' }, is_active: true, end_at: '2020-01-01' }], total: 1 } };
    const { root, app } = mount(SubscriptionsPage);
    try { await settle(); assert.match(text(root), /Returning Viewer/); assert.match(text(root), /Expired desktop/); assert.match(text(root), /Expired/); }
    finally { app.unmount(); }
});
