import { test } from 'node:test';
import assert from 'node:assert/strict';
import { runRequestBatch } from '../../resources/js/admin/lib/requestBatch.js';
const tick = () => new Promise(setImmediate);
test('writes are bounded to four concurrent requests', async () => {
    const started = []; const releases = [];
    const work = runRequestBatch(Array.from({ length: 6 }, (_, index) => () => { started.push(index); return new Promise(resolve => releases.push(resolve)); }));
    await tick(); assert.deepEqual(started, [0, 1, 2, 3]);
    releases.slice().forEach(resolve => resolve()); await tick();
    assert.deepEqual(started, [0, 1, 2, 3, 4, 5]);
    releases.slice(4).forEach(resolve => resolve()); await work;
});
test('a failed batch settles its running writes and does not start later batches', async () => {
    const started = []; let finish;
    const work = runRequestBatch(Array.from({ length: 6 }, (_, index) => () => {
        started.push(index); if (index === 0) throw new Error('Save failed');
        return index === 1 ? new Promise(resolve => { finish = resolve; }) : Promise.resolve();
    }));
    const checked = assert.rejects(work, /Save failed/);
    await tick(); assert.deepEqual(started, [0, 1, 2, 3]); finish(); await checked;
    assert.deepEqual(started, [0, 1, 2, 3]);
});
