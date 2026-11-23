import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';

// Mount the shared user lookup with real Vue reactivity and a fake API.
const temp = await mkdtemp(join(tmpdir(), 'zostream-movie-details-'));
after(() => rm(temp, { recursive: true, force: true }));
const outfile = join(temp, 'resource-ui.mjs');
await build({
    stdin: { contents: `
        export { createRenderer, nextTick, h, reactive } from 'vue';
        export { route, router } from 'vue-router';
        export { default as MoviesPage } from './resources/js/admin/pages/MoviesPage.vue';
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
const { createRenderer, nextTick, h, reactive, route, MoviesPage, ResourceEditorPage } = await import(pathToFileURL(outfile));
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
const movie = { id: 'public-film', num: 19, title: 'Searchable Film', director: 'Director One', genre: 'Adventure', description: 'Movie synopsis', duration: '120 minutes', status: 'Draft', poster: 'https://cdn.example/poster.webp', release_on: '2026-11-01', isSeason: false, isPremium: true, isPayPerView: true, ppv_amount: 49, isEnable: false, isProtected: true };
function input(root, label) {
    const parent = find(root, el => ['label', 'div'].includes(el.tag) && el.children.some(child => child.tag === 'span' && text(child).replace(/\*/g, '').trim() === label));
    assert.ok(parent, `Missing ${label}`); return find(parent, el => el.tag === 'input');
}
function fixture() {
    let record = { ...movie }; const writes = []; const calls = [];
    globalThis.__resourceApi = async (path, options) => {
        calls.push(path);
        if (path.includes('/search?')) return { data: [record], pagination: { total: 1, current_page: 1, last_page: 1 } };
        if (options.method) { writes.push({ path, ...options }); record = { ...record, ...options.body }; return { data: record }; }
        if (path.includes('/links?')) return { links: { hls_url: 'https://cdn.example/movie.m3u8', trailers: ['https://cdn.example/trailer.mp4'] } };
        return { data: record };
    };
    return { writes, calls };
}

test('Movies starts with search and selected movie details replace the results table', async () => {
    route.params = {}; route.query = {}; const { calls } = fixture();
    const { root, app } = mount(MoviesPage);
    try {
        await settle(); assert.equal(calls.length, 0); assert.match(text(root), /Find a movie to get started/);
        const search = find(root, el => el.tag === 'input'); search.props['onUpdate:modelValue']('Adventure'); search.props.onInput();
        await new Promise(resolve => setTimeout(resolve, 330)); await settle();
        find(root, el => el.props.role === 'option').props.onClick(); await settle();
        assert.match(text(root), /Searchable Film/); assert.match(text(root), /Movie synopsis/);
        assert.match(text(root), /Director One/); assert.match(text(root), /Pay per view/); assert.match(text(root), /INR 49/);
        assert.equal(find(root, el => el.tag === 'table'), undefined);
        assert.equal(find(root, el => el.props.role === 'listbox'), undefined);
        assert.equal(search.value, ''); assert.equal(route.query.movie, movie.id);
        assert.ok(find(root, el => el.tag === 'img' && el.props.src === movie.poster));
        assert.ok(find(root, el => el.tag === 'a' && el.props.href === 'https://cdn.example/movie.m3u8'));
    } finally { app.unmount(); }
});

test('movie edit opens in a dialog and Save/Cancel retain the selected title', async () => {
    route.params = {}; route.query = { movie: movie.id }; const { writes, calls } = fixture();
    globalThis.__resourceNavigation = null;
    const { root, app } = mount(MoviesPage);
    try {
        await settle(); find(root, el => el.tag === 'button' && text(el).trim() === 'Edit movie').props.onClick(); await settle();
        let dialog = find(root, el => el.tag === 'dialog'); assert.equal(dialog.open, true);
        assert.equal(calls.filter(path => path === '/admin/catalog/items/public-film').length, 1);
        assert.equal(calls.filter(path => path.includes('/links?')).length, 1);
        const form = find(dialog, el => el.tag === 'form');
        assert.equal(find(dialog, el => el.tag === 'button' && text(el) === 'Save changes').props.form, form.props.id);
        input(dialog, 'Title').props['onUpdate:modelValue']('Changed Film'); await settle();
        form.props.onSubmit({ preventDefault() {} }); await settle();
        assert.equal(writes[0].path, '/admin/catalog/items/public-film'); assert.equal(writes[0].body.title, 'Changed Film');
        assert.equal(find(root, el => el.tag === 'dialog'), undefined); assert.match(text(root), /Changed Film/);
        assert.equal(route.query.movie, movie.id); assert.equal(globalThis.__resourceNavigation, null);
        find(root, el => el.tag === 'button' && text(el).trim() === 'Edit movie').props.onClick(); await settle();
        dialog = find(root, el => el.tag === 'dialog'); input(dialog, 'Title').props['onUpdate:modelValue']('Discarded Film');
        find(dialog, el => el.tag === 'button' && text(el) === 'Cancel').props.onClick(); await settle();
        assert.equal(writes.length, 1); assert.doesNotMatch(text(root), /Discarded Film/); assert.match(text(root), /Changed Film/);
    } finally { app.unmount(); }
});

test('a failed movie save preserves the form and does not navigate away', async () => {
    route.params = {}; route.query = { movie: movie.id, edit: '1' };
    globalThis.__resourceApi = async (path, options) => {
        if (options.method) throw new Error('Title could not be saved.');
        return path.includes('/links?') ? { links: {} } : movie;
    };
    const { root, app } = mount(MoviesPage);
    try {
        await settle(); let dialog = find(root, el => el.tag === 'dialog');
        input(dialog, 'Title').props['onUpdate:modelValue']('Retry Film'); await settle();
        find(dialog, el => el.tag === 'form').props.onSubmit({ preventDefault() {} }); await settle();
        dialog = find(root, el => el.tag === 'dialog'); assert.equal(dialog.open, true);
        assert.equal(input(dialog, 'Title').value, 'Retry Film'); assert.match(text(dialog), /Title could not be saved/);
    } finally { app.unmount(); }
});

test('search pagination is usable and missing playback URLs do not hide movie details', async () => {
    route.params = {}; route.query = {};
    const pages = [];
    globalThis.__resourceApi = async path => {
        if (path.includes('/search?')) { const page = Number(new URL(path, 'https://test.invalid').searchParams.get('page')); pages.push(page); return { data: [{ ...movie, title: `Page ${page} Film` }], pagination: { current_page: page, last_page: 2, total: 13 } }; }
        if (path.includes('/links?')) throw Object.assign(new Error('No links'), { status: 404 });
        return movie;
    };
    const { root, app } = mount(MoviesPage);
    try {
        await settle(); const search = find(root, el => el.tag === 'input'); search.props['onUpdate:modelValue']('Film'); search.props.onInput();
        await new Promise(resolve => setTimeout(resolve, 330)); await settle();
        find(root, el => el.tag === 'button' && text(el) === 'Next').props.onClick(); await settle();
        assert.deepEqual(pages, [1, 2]); assert.match(text(root), /Page 2 Film/);
        find(root, el => el.props.role === 'option').props.onClick(); await settle();
        assert.match(text(root), /Movie synopsis/); assert.doesNotMatch(text(root), /Playback links could not be loaded/);
        assert.match(text(root), /Not set/);
    } finally { app.unmount(); }
});

test('failed editor loading cannot submit blank movie data', async () => {
    route.params = {}; route.query = { movie: movie.id };
    const writes = [];
    globalThis.__resourceApi = async (path, options) => {
        if (options.method) writes.push(options);
        if (path.includes('/links?')) throw Object.assign(new Error('Could not load editor links.'), { status: 503 });
        return movie;
    };
    const { root, app } = mount(MoviesPage);
    try {
        await settle(); find(root, el => el.tag === 'button' && text(el).trim() === 'Edit movie').props.onClick(); await settle();
        const dialog = find(root, el => el.tag === 'dialog');
        assert.equal(find(dialog, el => el.tag === 'button' && text(el) === 'Save changes').props.disabled, true);
        assert.equal(find(dialog, el => el.tag === 'form'), undefined); assert.equal(writes.length, 0);
        assert.match(text(dialog), /Retry record/);
    } finally { app.unmount(); }
});

test('movie details render without waiting for slow playback links', async () => {
    route.params = {}; route.query = { movie: movie.id };
    let finishLinks;
    globalThis.__resourceApi = path => path.includes('/links?') ? new Promise(resolve => { finishLinks = resolve; }) : Promise.resolve(movie);
    const { root, app } = mount(MoviesPage);
    try {
        await settle(); assert.match(text(root), /Movie synopsis/); assert.match(text(root), /Loading playback links/);
        finishLinks({ links: { hls_url: 'https://cdn.example/ready.m3u8' } }); await settle();
        assert.ok(find(root, el => el.tag === 'a' && el.props.href === 'https://cdn.example/ready.m3u8'));
    } finally { app.unmount(); }
});

test('lookup pagination works without an expensive total count', async () => {
    route.params = {}; route.query = {};
    globalThis.__resourceApi = async path => {
        const page = Number(new URL(path, 'https://test.invalid').searchParams.get('page'));
        return { data: [movie], pagination: { current_page: page, has_more: page === 1 } };
    };
    const { root, app } = mount(MoviesPage);
    try {
        await settle(); const search = find(root, el => el.tag === 'input');
        search.props['onUpdate:modelValue']('Film'); search.props.onInput(); await new Promise(resolve => setTimeout(resolve, 330)); await settle();
        const next = find(root, el => el.tag === 'button' && text(el) === 'Next'); assert.equal(next.props.disabled, false);
        next.props.onClick(); await settle();
        assert.match(text(root), /Page 2/); assert.equal(next.props.disabled, true);
    } finally { app.unmount(); }
});
