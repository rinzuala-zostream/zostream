// Bound write concurrency so a large season does not flood PHP workers.
export async function runRequestBatch(tasks, concurrency = 4) {
    if (!Number.isInteger(concurrency) || concurrency < 1) throw new RangeError('Concurrency must be a positive integer.');
    for (let offset = 0; offset < tasks.length; offset += concurrency) {
        const results = await Promise.allSettled(tasks.slice(offset, offset + concurrency).map(task => Promise.resolve().then(task)));
        const failure = results.find(result => result.status === 'rejected');
        if (failure) throw failure.reason;
    }
}
