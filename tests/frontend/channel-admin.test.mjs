import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';

// Compile the real Vue pages and mount them with Vue's host renderer. No browser,
// live API, session, payment provider or production database is used.
const temp = await mkdtemp(join(tmpdir(), 'zostream-channel-ui-'));
after(() => rm(temp, { recursive: true, force: true }));
const outfile = join(temp, 'channel-ui.mjs');
await build({
    stdin: { contents: `
        export { createRenderer, nextTick, h } from 'vue';
        export { route, router } from 'vue-router';
        export { default as FileDropzone } from './resources/js/admin/components/channels/FileDropzone.vue';
        export { default as CreatorApp } from './resources/js/creator/CreatorApp.vue';
        export { default as ChannelsPage } from './resources/js/admin/pages/ChannelsPage.vue';
        export { default as ChannelDetailPage } from './resources/js/admin/pages/ChannelDetailPage.vue';
    `, resolveDir: resolve('.'), loader: 'js' },
    outfile, bundle: true, platform: 'node', format: 'esm', logLevel: 'silent',
    plugins: [{ name: 'channel-vue-test', setup(builder) {
        builder.onResolve({ filter: /^vue-router$/ }, () => ({ path: 'router', namespace: 'channel-test' }));
        builder.onLoad({ filter: /.*/, namespace: 'channel-test' }, () => ({ contents: `
            import { reactive } from 'vue';
            export const route = reactive({ params: { id: '1' }, query: {} });
            export const router = { push: async path => { globalThis.__channelNavigation = path; }, replace: async location => { route.query = location.query; } };
            export const useRoute = () => route;
            export const useRouter = () => router;
            export const onBeforeRouteLeave = () => {};
            export const onBeforeRouteUpdate = () => {};
        `, resolveDir: resolve('.') }));
        builder.onLoad({ filter: /[/\\]admin[/\\]lib[/\\]api\.js$/ }, () => ({ contents: `
            export const api = (path, options = {}) => globalThis.__channelApi(path, options);
            export const apiBlob = path => globalThis.__channelApi(path, {});
            export const queryString = values => '?' + new URLSearchParams(Object.entries(values).filter(([, value]) => value !== '' && value != null));
        ` }));
        builder.onLoad({ filter: /[/\\]creator[/\\]api\.js$/ }, () => ({ contents: `
            export const api = (path, options = {}) => globalThis.__creatorApi(path, options);
            export const getSession = () => globalThis.__creatorSession;
            export const saveSession = value => { globalThis.__creatorSession = value; };
            export const clearSession = () => { globalThis.__creatorSession = null; };
            export const authData = value => value?.data || value;
            export const deviceId = () => 'creator-test-browser';
        ` }));
        builder.onLoad({ filter: /\.vue$/ }, async ({ path }) => {
            const { descriptor } = parse(await readFile(path, 'utf8'), { filename: path });
            return { contents: compileScript(descriptor, { id: path, inlineTemplate: true, templateOptions: { compilerOptions: { hoistStatic: false } } }).content, loader: 'js', resolveDir: resolve(path, '..') };
        });
    } }],
});
const { createRenderer, nextTick, h, route, router, ChannelsPage, ChannelDetailPage, CreatorApp, FileDropzone } = await import(pathToFileURL(outfile));
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
const renderer = createRenderer({
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
function button(root, label) { const found = find(root, el => el.tag === 'button' && text(el).trim() === label); assert.ok(found, `Missing button: ${label}`); return found; }
async function settle() { for (let i = 0; i < 4; i++) { await nextTick(); await new Promise(setImmediate); } }
async function click(root, label) { button(root, label).props.onClick({ preventDefault() {} }); await settle(); }
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
const channel = { id: 1, user_id: 'owner', name: 'Sample Channel', slug: 'sample', status: 'active', is_verified: false };
const video = { id: 3, channel_id: 1, title: 'Sample video', content_type: 'video', access_type: 'ppv', status: 'draft', duration: 0, media: [{ id: 5, media_type: 'video', url: 'https://cdn.example/manifest.mpd' }] };
const list = (data, extra = {}) => ({ data, current_page: 1, last_page: 1, total: data.length, ...extra });

test('channel list filters all pages and creates a channel using a field-only V4 payload', async () => {
    const calls = []; globalThis.__channelNavigation = null;
    globalThis.__channelApi = async (path, options) => {
        calls.push({ path, ...options });
        return options.method === 'POST' ? { ...channel, id: 7 } : list([channel], { last_page: 2, total: 21 });
    };
    const { root, app } = mount(ChannelsPage);
    try {
        await settle(); assert.match(text(root), /Sample Channel/);
        await click(root, 'Next'); assert.match(calls.at(-1).path, /page=2/);
        await click(root, 'Create channel');
        input(root, 'Channel owner UID', 'owner'); input(root, 'Channel name', 'Created channel');
        await submit(root, 'Channel owner UID');
        const created = calls.find(call => call.method === 'POST');
        assert.equal(created.path, '/admin/channels'); assert.equal(created.body.name, 'Created channel');
        assert.equal(created.body.user_id, 'owner'); assert.equal(created.body.status, 'pending');
        assert.equal(Object.hasOwn(created.body, 'slug'), false); assert.equal(Object.hasOwn(created.body, 'id'), false);
        assert.equal(globalThis.__channelNavigation, '/channels/7');
    } finally { app.unmount(); }
});

test('detail UI saves plans, media, rental settings and loads both history types through V4', async () => {
    route.params.id = '1'; route.query = { tab: 'plans' };
    const calls = [];
    globalThis.__channelApi = async (path, options) => {
        calls.push({ path, ...options });
        if (path === '/admin/channels/1') return { ...channel };
        if (path.includes('/uploads?')) return list([]);
        if (path.startsWith('/admin/channels/1/plans')) return options.method ? { id: 2 } : list([{ id: 2, name: 'Monthly', price: 88, duration_days: 30, is_active: true }]);
        if (path.startsWith('/admin/channels/1/contents')) return list([video]);
        if (path.startsWith('/admin/channels/1/subscription-history')) return list([{ id: 9, user_id: 'viewer', status: 'success', amount: 88 }]);
        if (path.startsWith('/admin/channel-contents/3/rental-history')) return list([{ id: 8, user_id: 'viewer', status: 'success', amount: 10 }]);
        if (path === '/admin/channel-contents/3') return { ...video };
        if (options.method) return { id: 5 };
        throw new Error(`Unexpected API: ${path}`);
    };
    const { root, app } = mount(ChannelDetailPage);
    try {
        await settle(); await click(root, 'Add plan');
        input(root, 'Plan name', 'Monthly'); input(root, 'Price (INR)', '88');
        await submit(root, 'Plan name');
        const saved = calls.find(call => call.method === 'POST');
        assert.equal(saved.path, '/admin/channels/1/plans'); assert.equal(saved.body.price, 88); assert.equal(saved.body.duration_days, 30);
        assert.equal(Object.hasOwn(saved.body, 'final_price'), false);
        await click(root, 'contents'); await click(root, 'Media & details');
        assert.match(text(root), /https:\/\/cdn.example\/manifest.mpd/);
        await click(root, 'Attach media'); input(root, 'Media URL', 'https://cdn.example/new.mpd');
        await submit(root, 'Media reference');
        assert.ok(calls.some(call => call.method === 'POST' && call.path === '/admin/channel-contents/3/media' && call.body.url.endsWith('new.mpd')));
        input(root, 'Rental price (INR)', '10'); await submit(root, 'Rental settings');
        assert.ok(calls.some(call => call.method === 'PUT' && call.path === '/admin/channel-contents/3/ppv' && call.body.price === 10));
        await click(root, 'Load history'); assert.ok(calls.some(call => call.path.includes('/rental-history')));
        await click(root, 'Payment history'); assert.ok(calls.some(call => call.path.includes('/subscription-history')));
        assert.match(text(root), /viewer/);
    } finally { app.unmount(); }
});

test('a failed V4 write keeps entered values and displays the server error', async () => {
    route.params.id = '1'; route.query = { tab: 'profile' };
    globalThis.__channelApi = async (path, options) => {
        if (options.method) throw new Error('Channel name is invalid.');
        return { ...channel };
    };
    const { root, app } = mount(ChannelDetailPage);
    try {
        await settle(); input(root, 'Channel name', 'Unsaved name'); await submit(root, 'Channel profile');
        assert.match(text(root), /Channel name is invalid/);
        const name = find(root, el => el.tag === 'input' && el.value === 'Unsaved name');
        assert.ok(name, 'Entered name was retained after rejection');
    } finally { app.unmount(); }
});

test('revocation requires confirmation and affects only the selected channel member', async () => {
    route.params.id = '1'; route.query = { tab: 'subscribers' };
    const calls = [];
    globalThis.__channelApi = async (path, options) => {
        calls.push({ path, ...options });
        if (path === '/admin/channels/1') return { ...channel };
        if (path.includes('/uploads?')) return list([]);
        if (options.method) return { status: 'expired' };
        return list([{ id: 12, user_id: 'member/one', user: { name: 'Member One' }, status: 'active', expires_at: '2030-01-01' }]);
    };
    const { root, app } = mount(ChannelDetailPage);
    try {
        await settle(); globalThis.confirm = () => false;
        await click(root, 'Revoke access'); assert.equal(calls.some(call => call.method === 'DELETE'), false);
        globalThis.confirm = () => true;
        await click(root, 'Revoke access');
        assert.ok(calls.some(call => call.method === 'DELETE' && call.path === '/admin/channels/1/subscribers/member%2Fone'));
        assert.match(text(root), /Membership access revoked/);
    } finally { globalThis.confirm = () => true; app.unmount(); }
});


test('creator studio edits own profile and creates drafts without administrative fields', async () => {
    globalThis.__creatorSession = { access_token: 'owner-token', uid: 'owner' };
    const calls = [];
    globalThis.__creatorApi = async (path, options) => {
        calls.push({ path, ...options });
        if (path.startsWith('/creator/channels?')) return list([channel]);
        if (path === '/creator/channels/1') return options.method ? channel : { channel, summary: { contents: 0, published: 0, active_members: 0 } };
        if (path.startsWith('/creator/channels/1/contents')) return options.method ? { ...video, status: 'draft' } : list([]);
        throw new Error(`Unexpected creator request: ${path}`);
    };
    const { root, app } = mount(CreatorApp);
    try {
        await settle(); assert.match(text(root), /Sample Channel/);
        input(root, 'Channel name', 'Owner updated'); await submit(root, 'Channel profile');
        const profile = calls.find(call => call.method === 'PUT');
        assert.equal(profile.path, '/creator/channels/1'); assert.equal(profile.body.name, 'Owner updated');
        assert.equal(Object.hasOwn(profile.body, 'user_id'), false); assert.equal(Object.hasOwn(profile.body, 'status'), false);
        await click(root, 'Video drafts'); await click(root, 'New video draft');
        input(root, 'Title', 'My draft'); await submit(root, 'Video details');
        const draft = calls.find(call => call.method === 'POST');
        assert.equal(draft.path, '/creator/channels/1/contents'); assert.equal(draft.body.title, 'My draft');
        assert.equal(Object.hasOwn(draft.body, 'status'), false); assert.equal(Object.hasOwn(draft.body, 'url'), false);
        assert.equal(calls.some(call => call.path.startsWith('/admin/')), false);
    } finally { app.unmount(); }
});

test('unassigned creator receives an assignment message and no channel editing controls', async () => {
    globalThis.__creatorSession = { access_token: 'viewer-token', uid: 'viewer' };
    globalThis.__creatorApi = async () => list([]);
    const { root, app } = mount(CreatorApp);
    try {
        await settle(); assert.match(text(root), /No channel assigned/); assert.match(text(root), /viewer/);
        assert.equal(find(root, el => el.tag === 'button' && text(el).trim() === 'New video draft'), undefined);
    } finally { app.unmount(); }
});

test('creator API refresh uses its own session without overwriting admin login', async () => {
    const storage = new Map([
        ['zostream_admin_session_v1', JSON.stringify({ access_token: 'admin-token' })],
        ['zostream_creator_session_v1', JSON.stringify({ access_token: 'old-owner-token', refresh_token: 'owner-refresh', uid: 'owner' })],
    ]);
    globalThis.localStorage = { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) };
    const originalFetch = globalThis.fetch; const calls = [];
    const actual = await import('../../resources/js/creator/api.js');
    globalThis.fetch = async (path, options) => {
        calls.push({ path, ...options });
        if (path.endsWith('/auth/tokens/refresh')) return new Response(JSON.stringify({ success: true, data: { access_token: 'new-owner-token', refresh_token: 'new-owner-refresh' } }), { status: 200 });
        if (options.headers.Authorization === 'Bearer old-owner-token') return new Response(JSON.stringify({ success: false }), { status: 401 });
        return new Response(JSON.stringify({ success: true, data: { channels: [] } }), { status: 200 });
    };
    try {
        await actual.api('/creator/channels');
        assert.equal(calls.at(-1).headers.Authorization, 'Bearer new-owner-token');
        assert.equal(calls.at(-1).headers['X-Client-Platform'], 'web');
        assert.equal(JSON.parse(storage.get('zostream_admin_session_v1')).access_token, 'admin-token');
        assert.equal(actual.getSession().uid, 'owner');
        assert.equal(JSON.parse(calls[1].body).refresh_token, 'owner-refresh');
    } finally { globalThis.fetch = originalFetch; }
});


test('drag-and-drop uploads chunks and emits a completed private source', async () => {
    const calls = []; let uploaded = null;
    const api = async (path, options = {}) => {
        calls.push({ path, ...options });
        if (path.endsWith('/uploads')) return { id: 'upload-one', chunk_size: 3 };
        if (path.includes('/parts/')) return {};
        if (path.endsWith('/complete')) return { id: 'upload-one', status: 'ready', url: null };
        throw new Error(`Unexpected upload: ${path}`);
    };
    const { root, app } = mount({ render: () => h(FileDropzone, { api, channelId: 1, contentId: 3, kind: 'source', audience: 'creator', onUploaded: value => uploaded = value }) });
    try {
        const dropzone = find(root, el => typeof el.props.onDrop === 'function');
        dropzone.props.onDrop({ preventDefault() {}, dataTransfer: { files: [new File(['abcdefg'], 'source.mp4', { type: 'video/mp4' })] } });
        await settle(); await click(root, 'Upload selected file');
        assert.equal(calls[0].path, '/creator/channels/1/uploads');
        assert.equal(calls[0].body.content_id, 3); assert.equal(calls[0].body.size, 7);
        const chunks = calls.filter(call => call.path.includes('/parts/'));
        assert.equal(chunks.length, 3);
        assert.deepEqual(await Promise.all(chunks.map(call => call.body.get('file').text())), ['abc', 'def', 'g']);
        assert.equal(uploaded.status, 'ready'); assert.equal(uploaded.url, null);
        assert.match(text(root), /Source uploaded for staff review/);
    } finally { app.unmount(); }
});

test('file picker retry reuses its session and does not create duplicate uploads', async () => {
    const calls = []; let fail = true;
    const api = async (path, options = {}) => {
        calls.push({ path, ...options });
        if (path.endsWith('/uploads')) return { id: 'upload-retry', chunk_size: 10 };
        if (path.endsWith('/upload-retry')) return { status: 'uploading' };
        if (path.includes('/parts/') && fail) { fail = false; throw new Error('Connection interrupted'); }
        if (path.endsWith('/complete')) return { status: 'ready', url: 'https://art.example/image.webp' };
        return {};
    };
    const { root, app } = mount({ render: () => h(FileDropzone, { api, channelId: 1 }) });
    try {
        const picker = find(root, el => el.tag === 'input' && el.props.type === 'file');
        picker.props.onChange({ target: { files: [new File(['image'], 'cover.png')] } });
        await settle(); await click(root, 'Upload selected file'); assert.match(text(root), /Connection interrupted/);
        await click(root, 'Retry upload');
        assert.equal(calls.filter(call => call.path.endsWith('/uploads')).length, 1);
        assert.equal(calls.filter(call => call.path.includes('/parts/')).length, 2);
        assert.match(text(root), /Image uploaded/);
    } finally { app.unmount(); }
});

test('creator can filter drafts and inspect paginated private source history', async () => {
    globalThis.__creatorSession = { access_token: 'owner-token', uid: 'owner' };
    const calls = [];
    globalThis.__creatorApi = async (path, options) => {
        calls.push({ path, ...options });
        if (path.startsWith('/creator/channels?')) return list([channel]);
        if (path === '/creator/channels/1') return { channel, summary: { contents: 1, published: 0, active_members: 0 } };
        if (path.includes('/uploads?')) return list([{ id: 'source-a', filename: 'original_source.mp4', size: 2048 }], { last_page: 2, total: 21 });
        if (path.includes('/contents?')) return list([video]);
        throw new Error(`Unexpected request: ${path}`);
    };
    const { root, app } = mount(CreatorApp);
    try {
        await settle(); await click(root, 'Video drafts');
        const filter = find(root, el => el.tag === 'select' && el.props['onUpdate:modelValue'] && el.children.some(child => text(child) === 'Drafts'));
        filter.props['onUpdate:modelValue']('draft'); await nextTick(); filter.props.onChange(); await settle();
        assert.ok(calls.some(call => call.path.includes('/contents?') && call.path.includes('status=draft')));
        await click(root, 'Upload MP4');
        assert.match(text(root), /original_source\.mp4/);
        assert.ok(calls.some(call => call.path === '/creator/channels/1/uploads?content_id=3&page=1'));
        await click(root, 'Next');
        assert.ok(calls.some(call => call.path === '/creator/channels/1/uploads?content_id=3&page=2'));
        assert.equal(calls.some(call => call.path.includes('/download') || call.path.startsWith('/admin/')), false);
    } finally { app.unmount(); }
});

test('profile artwork upload locks studio navigation while keeping cancellation usable', async () => {
    globalThis.__creatorSession = { access_token: 'owner-token', uid: 'owner' };
    let finishChunk; const chunk = new Promise(resolve => { finishChunk = resolve; });
    const calls = [];
    globalThis.__creatorApi = async (path, options) => {
        calls.push({ path, ...options });
        if (path.startsWith('/creator/channels?')) return list([channel]);
        if (path === '/creator/channels/1') return { channel, summary: { contents: 0, published: 0, active_members: 0 } };
        if (path.endsWith('/uploads')) return { id: 'artwork-lock', chunk_size: 100 };
        if (path.includes('/parts/')) return chunk;
        if (options.method === 'DELETE') return {};
        throw new Error(`Unexpected request: ${path}`);
    };
    const { root, app } = mount(CreatorApp);
    try {
        await settle();
        const picker = find(root, el => el.tag === 'input' && el.props.type === 'file');
        picker.props.onChange({ target: { files: [new File(['image'], 'logo.png')] } });
        await settle(); await click(root, 'Upload selected file');
        assert.equal(button(root, 'Video drafts').props.disabled, true);
        assert.equal(button(root, 'Refresh').props.disabled, true);
        const cancel = button(root, 'Cancel upload');
        for (let parent = cancel.parent; parent; parent = parent.parent) {
            if (parent.tag === 'fieldset') assert.equal(Boolean(parent.props.disabled), false);
        }
        await click(root, 'Cancel upload'); finishChunk({}); await settle();
        assert.ok(calls.some(call => call.method === 'DELETE' && call.path.endsWith('/artwork-lock')));
        assert.equal(button(root, 'Video drafts').props.disabled, false);
        assert.equal(button(root, 'Refresh').props.disabled, false);
    } finally { finishChunk({}); app.unmount(); }
});

test('admin filters can be cleared without retaining the previous page or status', async () => {
    const calls = [];
    globalThis.__channelApi = async path => { calls.push(path); return list([channel], { last_page: 2, total: 21 }); };
    const { root, app } = mount(ChannelsPage);
    try {
        await settle();
        const filter = find(root, el => el.tag === 'select');
        filter.props['onUpdate:modelValue']('pending'); await settle();
        await click(root, 'Next');
        assert.match(calls.at(-1), /status=pending/);
        await click(root, 'Clear filters');
        assert.match(calls.at(-1), /page=1/);
        assert.doesNotMatch(calls.at(-1), /status=/);
    } finally { app.unmount(); }
});
