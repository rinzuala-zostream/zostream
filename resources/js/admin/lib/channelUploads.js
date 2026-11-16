// Small chunks avoid changing PHP/proxy limits for existing Zo Stream requests.
export async function uploadChannelFile({ api, channelId, contentId, audience = 'admin', kind, file, progress = () => {}, isCancelled = () => false, previous = null }) {
    const base = `/${audience}/channels/${encodeURIComponent(channelId)}/uploads`;
    const upload = previous || await api(base, { method: 'POST', body: { kind, filename: file.name, size: file.size, content_id: contentId || null } });
    const path = `${base}/${upload.id}`;
    if (previous) {
        const stored = await api(path);
        if (stored.status === 'ready') { progress(100); return stored; }
    }
    const count = Math.ceil(file.size / upload.chunk_size);
    try {
        // Replaying a chunk is safe; retry the same selected file after a failure.
        for (let index = 0; index < count; index++) {
            if (isCancelled()) { await api(path, { method: 'DELETE' }); return null; }
            const body = new FormData();
            body.append('file', file.slice(index * upload.chunk_size, (index + 1) * upload.chunk_size), 'chunk.bin');
            await api(`${path}/parts/${index}`, { method: 'POST', body });
            progress(Math.round((index + 1) / count * 95));
        }
        if (isCancelled()) { await api(path, { method: 'DELETE' }); return null; }
        const result = await api(`${path}/complete`, { method: 'POST' });
        progress(100);
        return result;
    } catch (error) { error.uploadSession = upload; throw error; }
}
