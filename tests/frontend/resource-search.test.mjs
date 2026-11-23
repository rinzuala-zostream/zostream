import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';

// Exercise the real list and editor with a fake API and Vue host renderer.
const temp = await mkdtemp(join(tmpdir(), 'zostream-resource-search-'));
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
const movie = { id: 11, num: 11, title: 'Search movie', seasons: [{ id: 22, title: 'Season one', season_number: 1, episodes: [{ id: 33, title: 'Episode original', episode_number: 1 }] }] };
for (const resource of ['movies', 'seasons', 'episodes']) {
    test(`${resource}: edit, cancel and save retain search, page and episode selection`, async () => {
        route.params = { resource };
        route.query = { q: 'Search', page: '2', ...(resource === 'episodes' ? { movie: '11', season: '22' } : {}) };
        const calls = [];
        let updated = false;
        globalThis.__resourceApi = async (path, options) => {
            calls.push({ path, ...options });
            if (options.method === 'PUT') { updated = true; return { id: resource === 'movies' ? 11 : resource === 'seasons' ? 22 : 33 }; }
            if (path.includes('/search?')) return resource === 'movies'
                ? { data: [{ ...movie, title: updated ? 'Search movie updated' : movie.title }], last_page: 3 }
                : { data: [{ ...movie, seasons: movie.seasons.map(season => ({ ...season, episodes: season.episodes.map(episode => ({ ...episode, title: updated ? 'Episode updated' : episode.title })) })) }] };
            if (path.includes('/links')) return { links: [] };
            return resource === 'movies' ? movie : resource === 'seasons' ? movie.seasons[0] : movie.seasons[0].episodes[0];
        };
        let mounted = mount(ResourceListPage);
        await settle();
        assert.equal(find(mounted.root, el => el.tag === 'input').value, 'Search');
        const edit = find(mounted.root, el => el.tag === 'a' && el.props.href?.path?.match(/\/\d+$/));
        assert.ok(edit, 'The restored list contains editable results');
        const target = edit.props.href;
        assert.equal(target.query.q, 'Search');
        assert.equal(target.query.page, '2');
        if (resource === 'episodes') {
            assert.match(text(mounted.root), /Episodes in Season one/);
            assert.equal(target.query.movie, '11'); assert.equal(target.query.season, '22');
        }
        mounted.app.unmount();
        route.params = { resource, id: target.path.split('/').at(-1) }; route.query = target.query;
        mounted = mount(ResourceEditorPage); await settle();
        const cancel = find(mounted.root, el => el.tag === 'a' && text(el) === 'Cancel');
        assert.deepEqual(cancel.props.href, { path: `/manage/${resource}`, query: target.query });
        const back = find(mounted.root, el => el.tag === 'a' && el.props.class === 'admin-back');
        assert.deepEqual(back.props.href, cancel.props.href);
        const form = find(mounted.root, el => el.tag === 'form');
        form.props.onSubmit({ preventDefault() {} }); await settle();
        assert.ok(calls.some(call => call.method === 'PUT'));
        assert.deepEqual(globalThis.__resourceNavigation, cancel.props.href);
        mounted.app.unmount();
        route.params = { resource }; route.query = globalThis.__resourceNavigation.query;
        mounted = mount(ResourceListPage); await settle();
        assert.ok(calls.filter(call => call.path.includes('/search?')).every(call => call.path.includes('q=Search') && call.path.includes('page=2')));
        if (resource === 'episodes') assert.match(text(mounted.root), /Episode updated/);
        if (resource === 'movies') assert.match(text(mounted.root), /Search movie updated/);
        mounted.app.unmount();
    });
}

test('episode lists retain an explicit season filter when opening an editor', async () => {
    route.params = { resource: 'episodes' }; route.query = { parent: '22', page: '3' };
    const calls = [];
    globalThis.__resourceApi = async path => { calls.push(path); return { data: [{ id: 33, title: 'Filtered episode' }], last_page: 4 }; };
    const { root, app } = mount(ResourceListPage);
    try {
        await settle();
        assert.match(calls[0], /\/catalog\/seasons\/22\/episodes\?/);
        assert.match(calls[0], /page=3/);
        const edit = find(root, el => el.tag === 'a' && el.props.href?.path?.endsWith('/33'));
        assert.equal(edit.props.href.query.parent, '22');
        assert.equal(edit.props.href.query.page, '3');
    } finally { app.unmount(); }
});
