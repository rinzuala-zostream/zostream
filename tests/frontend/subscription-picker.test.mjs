import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';

// Exercise plan selection and payment totals in the real subscription form.
const temp = await mkdtemp(join(tmpdir(), 'zostream-plan-picker-'));
after(() => rm(temp, { recursive: true, force: true }));
const outfile = join(temp, 'resource-ui.mjs');
await build({
    stdin: { contents: `
        export { createRenderer, nextTick, h, reactive } from 'vue';
        export { route, router } from 'vue-router';
        export { default as SubscriptionCreateForm } from './resources/js/admin/components/SubscriptionCreateForm.vue';
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
const { createRenderer, nextTick, h, reactive, SubscriptionCreateForm } = await import(pathToFileURL(outfile));
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
const plans = [
    { id: 1, name: 'Mobile monthly', device_type: 'mobile', price: 99, duration_days: 30 },
    { id: 2, name: 'Desktop yearly', device_type: 'desktop', price: 999, duration_days: 365 },
];
function form() {
    const model = reactive({ user_id: 'subscriber', plan_id: [], amount: '', currency: 'INR', is_active: true });
    let saves = 0;
    return { ...mount({ setup: () => () => h(SubscriptionCreateForm, { model, plans, onSave: () => saves++ }) }), model, saves: () => saves };
}
test('plans start collapsed, allow multiple selections and update payment totals', async () => {
    const { root, app, model, saves } = form();
    try {
        const picker = find(root, el => el.tag === 'details');
        assert.ok(picker);
        assert.ok(!picker.props.open);
        assert.match(text(find(picker, el => el.tag === 'summary')), /Choose plans/);
        const first = find(root, el => el.tag === 'label' && text(el).includes('Mobile monthly'));
        find(first, el => el.tag === 'input').props.onChange(); await settle();
        const second = find(root, el => el.tag === 'label' && text(el).includes('Desktop yearly'));
        find(second, el => el.tag === 'input').props.onChange(); await settle();
        assert.deepEqual([...model.plan_id], ['1', '2']);
        assert.match(text(find(picker, el => el.tag === 'summary')), /2 plans selected/);
        assert.match(text(find(root, el => el.props.class === 'summary-total')), /1,098/);
        const submit = find(root, el => el.tag === 'button' && el.props.type === 'submit');
        assert.equal(submit.props.disabled, false);
        find(root, el => el.tag === 'form').props.onSubmit({ preventDefault() {} });
        assert.equal(saves(), 1);
        find(root, el => el.tag === 'button' && el.props['aria-label'] === 'Remove Mobile monthly').props.onClick(); await settle();
        assert.deepEqual([...model.plan_id], ['2']);
        find(root, el => el.tag === 'button' && text(el) === 'Clear all').props.onClick(); await settle();
        assert.deepEqual([...model.plan_id], []);
        assert.equal(submit.props.disabled, true);
    } finally { app.unmount(); }
});
test('search filters plan choices without dropping selected plans; Done collapses the picker', async () => {
    const { root, app, model } = form();
    try {
        model.plan_id = ['1']; await settle();
        const picker = find(root, el => el.tag === 'details');
        picker.open = true;
        const search = find(picker, el => el.tag === 'input' && el.props.type === 'search');
        search.props['onUpdate:modelValue']('Desktop'); await settle();
        const options = find(picker, el => el.props.class === 'plan-options');
        assert.doesNotMatch(text(options), /Mobile monthly/);
        assert.match(text(options), /Desktop yearly/);
        assert.match(text(find(root, el => el.props['aria-label'] === 'Selected plans')), /Mobile monthly/);
        search.props['onUpdate:modelValue']('no-such-plan'); await settle();
        assert.match(text(options), /No matching plans/);
        let focused = false;
        picker.querySelector = () => ({ focus() { focused = true; } });
        find(picker, el => el.tag === 'button' && text(el) === 'Done').props.onClick(); await settle();
        assert.equal(picker.open, false); assert.equal(focused, true);
        assert.deepEqual([...model.plan_id], ['1']);
        assert.match(text(options), /Desktop yearly/);
    } finally { app.unmount(); }
});
