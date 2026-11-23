import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';

// Mount the shared user lookup with real Vue reactivity and a fake API.
const temp = await mkdtemp(join(tmpdir(), 'zostream-user-lookup-'));
after(() => rm(temp, { recursive: true, force: true }));
const outfile = join(temp, 'resource-ui.mjs');
await build({
    stdin: { contents: `
        export { createRenderer, nextTick, h, reactive } from 'vue';
        export { route, router } from 'vue-router';
        export { default as UserLookup } from './resources/js/admin/components/UserLookup.vue';
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
const { createRenderer, nextTick, h, reactive, UserLookup } = await import(pathToFileURL(outfile));
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
function lookup(initial = '') {
    const model = reactive({ uid: initial });
    const selected = [];
    const mounted = mount({ setup: () => () => h(UserLookup, {
        modelValue: model.uid, 'onUpdate:modelValue': value => { model.uid = value; },
        onSelect: user => selected.push(user),
    }) });
    const field = find(mounted.root, el => el.tag === 'input');
    async function type(value) { field.props['onUpdate:modelValue'](value); field.props.onInput(); await settle(); }
    return { ...mounted, model, selected, field, type };
}
const pause = () => new Promise(resolve => setTimeout(resolve, 330));
const viewer = { uid: 'canonical-uid', username: 'zoviewer', name: 'Zo Viewer', auth_phone: '9876543210', mail: 'zo@example.com' };

test('typing a username returns identities and selecting one stores its canonical UID', async () => {
    const calls = [];
    globalThis.__resourceApi = async path => { calls.push(path); return { data: { data: [viewer] } }; };
    const ui = lookup();
    try {
        await ui.type('@zoviewer'); await pause(); await settle();
        assert.equal(new URL(calls[0], 'https://test.invalid').searchParams.get('q'), '@zoviewer');
        const result = find(ui.root, el => el.props.role === 'option');
        assert.match(text(result), /@zoviewer/); assert.match(text(result), /9876543210/);
        assert.match(text(result), /canonical-uid/);
        result.props.onClick(); await settle();
        assert.equal(ui.model.uid, 'canonical-uid');
        assert.equal(ui.field.value, 'canonical-uid');
        assert.equal(ui.selected.length, 1);
        assert.equal(find(ui.root, el => el.props.role === 'listbox'), undefined);
        assert.match(text(ui.root), /Selected user|Zo Viewer/);
        await ui.type(''); assert.equal(ui.model.uid, '');
    } finally { ui.app.unmount(); }
});

test('obsolete replies cannot replace newer results and lookup errors are visible', async () => {
    let finishOld; let oldSignal;
    globalThis.__resourceApi = (path, options) => path.includes('old')
        ? new Promise(resolve => { finishOld = resolve; oldSignal = options.signal; })
        : Promise.reject(new Error('Lookup unavailable'));
    const ui = lookup();
    try {
        await ui.type('old'); await pause();
        await ui.type('new');
        assert.equal(oldSignal.aborted, true);
        finishOld({ data: [viewer] }); await settle();
        assert.doesNotMatch(text(ui.root), /@zoviewer/);
        await pause(); await settle();
        assert.match(text(ui.root), /Lookup unavailable/);
        await ui.type('x'); await pause(); await settle();
        assert.doesNotMatch(text(ui.root), /Lookup unavailable/);
        assert.equal(ui.model.uid, 'x');
    } finally { ui.app.unmount(); }
});

test('keyboard selection and external resets retain correct UID values', async () => {
    globalThis.__resourceApi = async () => ({ data: [viewer] });
    const ui = lookup('existing-uid');
    const key = value => {
        const event = { key: value, preventDefault() {}, stopPropagation() {} };
        for (const handler of ui.field.props.onKeydown) handler(event);
    };
    try {
        await ui.type('zoviewer'); await pause(); await settle();
        key('ArrowUp'); await settle();
        assert.equal(find(ui.root, el => el.props.role === 'option').props['aria-selected'], true);
        key('Enter'); await settle(); assert.equal(ui.model.uid, viewer.uid);
        ui.model.uid = ''; await settle(); assert.equal(ui.field.value, '');
        assert.doesNotMatch(text(ui.root), /@zoviewer/);
    } finally { ui.app.unmount(); }
});
