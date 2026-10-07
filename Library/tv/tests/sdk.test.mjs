import test from 'node:test'
import assert from 'node:assert/strict'

import {
  MemoryAnalyticsStorage,
  PlaybackAnalyticsSession,
  ZoAnalyticsClient,
  createProductEvent,
} from '../dist/index.js'

const context = {
  platform: 'tv', network_type: 'wifi', app_version: '1.0.0', build_number: '1',
  os_version: 'Tizen 8', device_model: 'Samsung TV', device_category: 'tv',
  locale: 'en-IN', timezone: 'Asia/Kolkata',
}
const content = {
  id: 'movie-1', type: 'movie', series_id: null, season_id: null,
  episode_id: null, is_downloaded: false, autoplay: false,
}

test('produces contract-v1 playback measurements and completion', () => {
  let wall = Date.parse('2026-10-07T10:00:00Z')
  let uptime = 0
  const session = new PlaybackAnalyticsSession({
    content, context, sessionId: 'session-1',
    clock: { now: () => wall, uptime: () => uptime },
  })
  uptime = 500; session.recordFirstFrame(0); session.recordPlay(0)
  uptime = 30_500; session.tick(30_000, 100_000)
  uptime = 31_500; session.recordBufferingStarted(30_000)
  uptime = 33_500; session.recordBufferingEnded(30_000)
  uptime = 93_500; session.tick(90_000, 100_000)
  wall += 93_500
  const summary = session.finish('user_closed')

  assert.equal(summary.payload.schema_version, 1)
  assert.equal(summary.payload.result.completed, true)
  assert.equal(summary.payload.end_reason, 'completed')
  assert.equal(summary.payload.buffering.count, 1)
  assert.equal(summary.payload.buffering.total_ms, 2_000)
  assert.equal(summary.payload.timing.startup_ms, 500)
  assert.ok(summary.payload.timing.unique_watched_ms >= 90_000)
})

test('seeking to the end does not mark playback complete', () => {
  let uptime = 0
  const session = new PlaybackAnalyticsSession({
    content, context,
    clock: { now: () => Date.parse('2026-10-07T10:00:00Z') + uptime, uptime: () => uptime },
  })
  session.recordPlay(0); uptime = 5_000; session.tick(5_000, 100_000)
  session.recordSeek(5_000, 99_000)
  const summary = session.finish('user_closed')
  assert.equal(summary.payload.result.completed, false)
  assert.equal(summary.payload.end_reason, 'user_closed')
  assert.equal(summary.payload.interaction.seek_forward_ms, 94_000)
})

test('uploads with required headers without persisting credentials', async () => {
  const storage = new MemoryAnalyticsStorage()
  const calls = []
  const fetcher = async (url, init) => {
    calls.push({ url: String(url), init })
    return new Response('{}', { status: 200, headers: { 'content-type': 'application/json' } })
  }
  const client = new ZoAnalyticsClient({ storage, fetcher, baseUrl: 'https://zostream.in' })
  client.setCollectionEnabled(true)
  const event = createProductEvent('screen_viewed', 'app-session', { screen_name: 'home' })
  const credentials = { accessToken: 'secret-access', deviceToken: 'secret-device', ownerKey: 'user-1' }
  await client.track(event, context, credentials, true)

  assert.equal(calls.length, 1)
  assert.equal(calls[0].init.headers.Authorization, 'Bearer secret-access')
  assert.equal(calls[0].init.headers['Device-Token'], 'secret-device')
  const stored = storage.getItem('zoanalytics_queue_v1') ?? ''
  assert.equal(stored.includes('secret-access'), false)
  assert.equal(stored.includes('secret-device'), false)
})

test('kill switch blocks collection and requests', async () => {
  let requests = 0
  const client = new ZoAnalyticsClient({
    storage: new MemoryAnalyticsStorage(),
    fetcher: async () => { requests += 1; return new Response('{}', { status: 200 }) },
  })
  const credentials = { accessToken: 'access', deviceToken: 'device', ownerKey: 'owner' }
  await client.track(createProductEvent('app_opened', 'app-session'), context, credentials, true)
  assert.equal(requests, 0)
  assert.deepEqual(client.pendingCounts('owner'), { playback: 0, events: 0, errors: 0 })
})
