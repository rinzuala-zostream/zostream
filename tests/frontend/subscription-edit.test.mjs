import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';

// Mount the real subscription editor with a fake API and Vue host renderer.
const temp = await mkdtemp(join(tmpdir(), 'zostream-subscription-ui-'));
after(() => rm(temp, { recursive: true, force: true }));
const outfile = join(temp, 'subscription-ui.mjs');
await build({
    stdin: { contents: `
        export { createRenderer, nextTick, h } from 'vue';
        export { route, router } from 'vue-router';
        export { default as ResourceEditorPage } from './resources/js/admin/pages/ResourceEditorPage.vue';
    `, resolveDir: resolve('.'), loader: 'js' },
    outfile, bundle: true, platform: 'node', format: 'esm', logLevel: 'silent',
    plugins: [{ name: 'subscription-vue-test', setup(builder) {
        builder.onResolve({ filter: /^vue-router$/ }, () => ({ path: 'router', namespace: 'subscription-test' }));
        builder.onLoad({ filter: /.*/, namespace: 'subscription-test' }, () => ({ contents: `
            import { reactive } from 'vue';
            export const route = reactive({ params: { id: '1' }, query: {} });
            export const router = { push: async path => { globalThis.__subscriptionNavigation = path; }, replace: async location => { route.query = location.query; } };
            export const useRoute = () => route;
            export const useRouter = () => router;
            export const onBeforeRouteLeave = () => {};
            export const onBeforeRouteUpdate = () => {};
        `, resolveDir: resolve('.') }));
        builder.onLoad({ filter: /[/\\]admin[/\\]lib[/\\]api\.js$/ }, () => ({ contents: `
            export const api = (path, options = {}) => globalThis.__subscriptionApi(path, options);
            export const apiBlob = path => globalThis.__subscriptionApi(path, {});
            export const queryString = values => '?' + new URLSearchParams(Object.entries(values).filter(([, value]) => value !== '' && value != null));
        ` }));
        builder.onLoad({ filter: /\.vue$/ }, async ({ path }) => {
            const { descriptor } = parse(await readFile(path, 'utf8'), { filename: path });
            return { contents: compileScript(descriptor, { id: path, inlineTemplate: true, templateOptions: { compilerOptions: { hoistStatic: false } } }).content, loader: 'js', resolveDir: resolve(path, '..') };
        });
    } }],
});
const { createRenderer, nextTick, h, route, router, ResourceEditorPage } = await import(pathToFileURL(outfile));
globalThis.Document = class {};
globalThis.ShadowRoot = class {};
globalThis.document = Object.assign(new Document(), { activeElement: null });
globalThis.window = { addEventListener() {}, removeEventListener() {}, dispatchEvent() {} };
globalThis.confirm = () => true;
const node = (tag, text = '') => ({ tag, text, props: {}, children: [], parent: null, listeners: {}, tagName: tag.toUpperCase(),
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
function input(root, label, value) {
    const parent = find(root, el => el.tag === 'label' && el.children.some(child => child.tag === 'span' && text(child).includes(label)));
    assert.ok(parent, `Missing field: ${label}`);
    const field = find(parent, el => ['input', 'select', 'textarea'].includes(el.tag));
    field.props['onUpdate:modelValue'](value);
}
async function submit(root, title) {
    const form = find(root, el => el.tag === 'form' && text(el).includes(title));
    assert.ok(form, `Missing form: ${title}`); form.props.onSubmit({ preventDefault() {} }); await settle();
}

const mobile = { id: 1, name: 'Mobile monthly', device_type: 'mobile', price: 99, is_active: true };
const desktop = { id: 102, name: 'Desktop monthly', device_type: 'desktop', price: 199, is_active: true };
const record = { id: 5, user_id: 'subscriber', plan_id: 1, plan: mobile, start_at: '2026-11-01', end_at: '2026-11-30', is_active: true };

test('subscription edit loads Desktop from page two and saves only access fields with original dates', async () => {
    route.params = { resource: 'subscriptions', id: '5' };
    const calls = [];
    globalThis.__subscriptionApi = async (path, options) => {
        calls.push({ path, ...options });
        if (path.startsWith('/admin/plans?')) {
            return { status: 'success', data: { data: path.includes('page=2') ? [desktop] : [mobile], last_page: 2 } };
        }
        return record;
    };
    const { root, app } = mount(ResourceEditorPage);
    try {
        await settle();
        assert.ok(find(root, el => el.tag === 'optgroup' && el.props.label === 'Desktop (Mac / Windows)'));
        assert.doesNotMatch(text(root), /Transaction ID|Payment gateway/);
        const user = find(root, el => el.tag === 'label' && text(el).includes('User ID or phone'));
        assert.equal(find(user, el => el.tag === 'input').props.readonly, true);
        input(root, 'Plan', 102);
        await settle();
        assert.match(text(root), /Saving stops current subscription playback/);
        await submit(root, 'Starts at');
        const update = calls.find(call => call.method === 'PUT');
        assert.equal(update.path, '/admin/subscriptions/5');
        assert.deepEqual(update.body, { plan_id: 102, renewed_by: '', is_active: true });
        assert.equal(globalThis.__subscriptionNavigation, '/manage/subscriptions');
        input(root, 'Ends at', '2026-12-10');
        await submit(root, 'Starts at');
        const explicitDateEdit = calls.filter(call => call.method === 'PUT').at(-1);
        assert.equal(explicitDateEdit.body.end_at, '2026-12-10');
        assert.equal(Object.hasOwn(explicitDateEdit.body, 'start_at'), false);
    } finally { app.unmount(); }
});
