import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';

// Exercise the real list and editor with a fake API and Vue host renderer.
const temp = await mkdtemp(join(tmpdir(), 'zostream-editor-requests-'));
after(() => rm(temp, { recursive: true, force: true }));
const outfile = join(temp, 'resource-ui.mjs');
await build({
    stdin: { contents: `
        export { createRenderer, nextTick, h } from 'vue';
        export { route, router } from 'vue-router';
        export { default as ResourceListPage } from './resources/js/admin/pages/ResourceListPage.vue';
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
        builder.onLoad({ filter: /\.vue$/ }, async ({ path }) => {
            const { descriptor } = parse(await readFile(path, 'utf8'), { filename: path });
            return { contents: compileScript(descriptor, { id: path, inlineTemplate: true, templateOptions: { compilerOptions: { hoistStatic: false } } }).content, loader: 'js', resolveDir: resolve(path, '..') };
        });
    } }],
});
const { createRenderer, nextTick, h, route, router, ResourceEditorPage, ResourceListPage } = await import(pathToFileURL(outfile));
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
test('editor starts record and playback reads together instead of waiting for one then the other', async () => {
    route.params = { resource: 'episodes', id: 'episode-one' }; route.query = {};
    const calls = []; const finishes = {};
    globalThis.__resourceApi = path => { calls.push(path); return new Promise(resolve => { finishes[path] = resolve; }); };
    const { root, app } = mount(ResourceEditorPage);
    try {
        await settle();
        assert.ok(calls.includes('/catalog/episodes/episode-one'));
        const links = calls.find(path => path.includes('/links?type=episode')); assert.ok(links);
        finishes['/catalog/episodes/episode-one']({ id: 'episode-one', title: 'Parallel episode', season_id: 'season-one' });
        finishes[links]({ links: [{ url: 'https://cdn.example/episode.mpd', quality: 'HD', type: 'DASH' }] });
        await settle(); assert.ok(find(root, el => el.tag === 'form'));
    } finally { app.unmount(); }
});

test('subscription record fetch does not wait for all plan pages', async () => {
    route.params = { resource: 'subscriptions', id: 'subscription-one' }; route.query = {};
    const calls = []; let finishPlans;
    globalThis.__resourceApi = path => {
        calls.push(path);
        if (path.startsWith('/admin/plans?')) return new Promise(resolve => { finishPlans = resolve; });
        return Promise.resolve({ id: 'subscription-one', user_id: 'viewer', plan_id: 1, plan: { device_type: 'mobile' } });
    };
    const { root, app } = mount(ResourceEditorPage);
    try {
        await settle(); assert.ok(calls.includes('/admin/subscriptions/subscription-one'));
        finishPlans({ data: [{ id: 1, name: 'Monthly', device_type: 'mobile' }], last_page: 1 });
        await settle(); assert.ok(find(root, el => el.tag === 'form'));
    } finally { app.unmount(); }
});

const season = { id: 'season-one', movie_id: 1, title: 'Season one', season_number: 1, isPayPerView: true, amount: 100, episodes: [
    { id: 'ep-one', title: 'Episode one', isPayPerView: true, amount: 50 },
    { id: 'ep-two', title: 'Episode two', isPayPerView: true, amount: 50 },
] };
test('saving season metadata does not rewrite unchanged episode prices', async () => {
    route.params = { resource: 'seasons', id: season.id }; route.query = {};
    const writes = [];
    globalThis.__resourceApi = async (path, options) => { if (options.method) writes.push({ path, ...options }); return season; };
    const { root, app } = mount(ResourceEditorPage);
    try {
        await settle(); find(root, el => el.tag === 'form').props.onSubmit({ preventDefault() {} }); await settle();
        assert.deepEqual(writes.map(row => row.path), ['/admin/catalog/seasons/season-one']);
    } finally { app.unmount(); }
});

test('changing one episode price writes only that episode alongside the season', async () => {
    route.params = { resource: 'seasons', id: season.id }; route.query = {};
    const writes = [];
    globalThis.__resourceApi = async (path, options) => { if (options.method) writes.push({ path, ...options }); return season; };
    const { root, app } = mount(ResourceEditorPage);
    try {
        await settle(); find(root, el => el.tag === 'button' && text(el) === 'View episodes').props.onClick(); await settle();
        const amountLabel = find(teleportTarget, el => el.tag === 'label' && el.children.some(child => child.tag === 'span' && text(child) === 'PPV amount'));
        find(amountLabel, el => el.tag === 'input').props['onUpdate:modelValue'](60); await settle();
        find(teleportTarget, el => el.tag === 'button' && text(el) === 'Save PPV settings').props.onClick(); await settle();
        assert.deepEqual(writes.map(row => row.path), ['/admin/catalog/seasons/season-one', '/admin/catalog/episodes/ep-one']);
        assert.equal(writes[1].body.amount, 60);
    } finally { app.unmount(); }
});
