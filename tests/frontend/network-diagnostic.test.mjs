import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const reference = 'a170d85f-f64e-4aca-b0ba-6ba986b5853e';
const template = readFileSync(new URL('../../resources/views/network-check.blade.php', import.meta.url), 'utf8');
const source = template.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace('@json($id)', JSON.stringify(reference))
    .replace('@json($probeUrl)', JSON.stringify('/probe'))
    .replace('@json($reportUrl)', JSON.stringify('/report'))
    .replace('@json(csrf_token())', JSON.stringify('csrf-test'));

async function runPage(probes, saveResponse) {
    const elements = Object.fromEntries(['status', 'tests', 'retry', 'details'].map(id => [id, {
        textContent: '', children: [], disabled: true,
        replaceChildren() { this.children = []; },
        appendChild(item) { this.children.push(item); },
        addEventListener() {},
    }]));
    const requests = [];
    let onLoad;
    vm.runInNewContext(source, {
        document: {getElementById: id => elements[id], createElement: () => ({textContent: ''})},
        window: {addEventListener: (_, callback) => { onLoad = callback; }},
        navigator: {onLine: true}, performance: {now: () => 100, getEntriesByType: () => []},
        AbortController, setTimeout, clearTimeout,
        fetch: async (url, options) => {
            requests.push({url, options});
            const next = url === '/report' ? saveResponse : probes.shift();
            if (next instanceof Error) throw next;
            return next;
        },
    });
    await onLoad();
    return {elements, requests};
}

const response = (status, body) => ({status, ok: status >= 200 && status < 300, json: async () => body});
const probe = () => response(200, {ok: true, reference});
const saved = () => response(200, {saved: true, reference});

test('automatically runs three probes and saves one report with CSRF protection', async () => {
    const {elements, requests} = await runPage([probe(), probe(), probe()], saved());
    assert.match(elements.status.textContent, /Result saved. 3\/3/);
    assert.equal(requests.length, 4);
    const submission = requests[3];
    assert.equal(submission.options.headers['X-CSRF-TOKEN'], 'csrf-test');
    assert.equal(JSON.parse(submission.options.body).tests.length, 3);
    assert.equal(elements.retry.disabled, false);
});

test('records timeouts and HTML proxy error statuses while still saving the report', async () => {
    const timeout = Object.assign(new Error('aborted'), {name: 'AbortError'});
    const htmlError = {status: 502, ok: false, json: async () => { throw new SyntaxError('HTML'); }};
    const {elements, requests} = await runPage([timeout, htmlError, probe()], saved());
    const tests = JSON.parse(requests[3].options.body).tests;
    assert.equal(tests[0].error, 'timeout');
    assert.equal(tests[1].status, 502);
    assert.equal(tests[1].error, 'unexpected_response');
    assert.match(elements.status.textContent, /Result saved. 1\/3/);
});

test('failed uploads preserve details and never claim results were saved', async () => {
    const {elements} = await runPage([probe(), probe(), probe()], new TypeError('Failed to fetch'));
    assert.doesNotMatch(elements.status.textContent, /Result saved/);
    assert.match(elements.status.textContent, /copy\/screenshot/);
    assert.equal(JSON.parse(elements.details.textContent).reference, reference);
    assert.equal(elements.retry.disabled, false);
});
