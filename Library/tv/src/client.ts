import { AnalyticsQueue, browserAnalyticsStorage } from './queue.js'
import type { AnalyticsStorage, PendingPlayback, PendingProductEvent } from './queue.js'
import { ANALYTICS_SDK_VERSION } from './models.js'
import type {
  AnalyticsContext, AnalyticsCredentials, AnalyticsPlatform, PlaybackErrorEvent,
  PlaybackSummary, PresenceState, ProductEvent, RemoteAnalyticsConfiguration,
} from './models.js'

export interface AnalyticsClientConfiguration {
  baseUrl: string
  maxPendingSessions: number
  retentionDays: number
  requestTimeoutMs: number
  fetcher: typeof fetch
  storage: AnalyticsStorage
}

export class AnalyticsHttpError extends Error {
  constructor(readonly status: number, readonly retryable: boolean) {
    super(`Analytics request failed with HTTP ${status}`)
    this.name = 'AnalyticsHttpError'
  }
}

export class ZoAnalyticsClient {
  readonly configuration: AnalyticsClientConfiguration
  private readonly queue: AnalyticsQueue
  private collectionEnabled = false
  private work: Promise<void> = Promise.resolve()

  constructor(configuration: Partial<AnalyticsClientConfiguration> = {}) {
    const fetcher = configuration.fetcher ?? globalThis.fetch
    if (!fetcher) throw new Error('ZoAnalytics requires a Fetch API implementation')
    this.configuration = {
      baseUrl: 'https://zostream.in', maxPendingSessions: 500,
      retentionDays: 7, requestTimeoutMs: 15_000,
      storage: browserAnalyticsStorage(), ...configuration, fetcher,
    }
    if (!/^https:\/\//i.test(this.configuration.baseUrl)) throw new Error('Analytics base URL must use HTTPS')
    this.queue = new AnalyticsQueue(this.configuration.storage, this.configuration.maxPendingSessions, this.configuration.retentionDays)
  }

  setCollectionEnabled(enabled: boolean): void { this.collectionEnabled = enabled }
  isCollectionEnabled(): boolean { return this.collectionEnabled }
  saveLocally(summary: PlaybackSummary, ownerKey: string): void {
    if (this.collectionEnabled) this.queue.upsertPlayback(summary, ownerKey)
  }
  submit(summary: PlaybackSummary, credentials: AnalyticsCredentials): Promise<void> {
    if (!this.collectionEnabled) return Promise.resolve()
    this.queue.upsertPlayback(summary, credentials.ownerKey)
    return this.enqueue(() => this.flushNow(credentials))
  }
  track(event: ProductEvent, context: AnalyticsContext, credentials: AnalyticsCredentials, flushImmediately = false): Promise<void> {
    if (!this.collectionEnabled) return Promise.resolve()
    this.queue.enqueueEvent(event, context, credentials.ownerKey)
    return flushImmediately ? this.enqueue(() => this.flushEvents(credentials)) : Promise.resolve()
  }
  submitPlaybackError(event: PlaybackErrorEvent, sessionId: string, platform: AnalyticsPlatform, credentials: AnalyticsCredentials): Promise<void> {
    if (!this.collectionEnabled) return Promise.resolve()
    this.queue.enqueueError(event, sessionId, platform, credentials.ownerKey)
    return this.enqueue(() => this.flushErrors(credentials))
  }
  flush(credentials: AnalyticsCredentials): Promise<void> {
    return this.collectionEnabled ? this.enqueue(() => this.flushNow(credentials)) : Promise.resolve()
  }
  clearPending(ownerKey: string): void { this.queue.clear(ownerKey) }
  pendingCounts(ownerKey: string): { playback: number; events: number; errors: number } { return this.queue.counts(ownerKey) }

  async updatePresence(state: PresenceState, context: AnalyticsContext, credentials: AnalyticsCredentials): Promise<void> {
    if (!this.collectionEnabled) return
    try { await this.request('POST', 'api/v4/analytic/presence', { state, context }, credentials, context.platform) } catch { /* Best effort. */ }
  }

  async fetchRemoteConfiguration(credentials: AnalyticsCredentials, platform: AnalyticsPlatform): Promise<RemoteAnalyticsConfiguration | null> {
    if (!this.collectionEnabled) return null
    const response = await this.request('GET', 'api/v4/analytic/config', null, credentials, platform) as { data?: RemoteAnalyticsConfiguration }
    return response.data ?? null
  }

  private enqueue(task: () => Promise<void>): Promise<void> {
    const result = this.work.then(task); this.work = result.catch(() => undefined); return result
  }
  private async flushNow(credentials: AnalyticsCredentials): Promise<void> {
    if (!this.collectionEnabled) return
    await this.flushErrors(credentials); await this.flushPlayback(credentials); await this.flushEvents(credentials)
  }
  private async flushPlayback(credentials: AnalyticsCredentials): Promise<void> {
    const pending = this.queue.duePlayback(credentials.ownerKey, 20); if (!pending.length) return
    const ids = new Set(pending.map((i) => i.session_id))
    try {
      pending.length === 1 ? await this.sendSinglePlayback(pending[0]!, credentials) : await this.sendPlaybackBatch(pending, credentials)
      this.queue.removePlayback(credentials.ownerKey, ids)
    } catch (error) {
      if (error instanceof AnalyticsHttpError && [413, 422].includes(error.status) && pending.length > 1) {
        await this.isolatePlayback(pending, credentials); return
      }
      this.shouldRetry(error) ? this.queue.deferPlayback(credentials.ownerKey, ids) : this.queue.removePlayback(credentials.ownerKey, ids)
      throw error
    }
  }
  private async isolatePlayback(items: PendingPlayback[], credentials: AnalyticsCredentials): Promise<void> {
    for (const item of items) {
      const id = new Set([item.session_id])
      try { await this.sendSinglePlayback(item, credentials); this.queue.removePlayback(credentials.ownerKey, id) }
      catch (error) { this.shouldRetry(error) ? this.queue.deferPlayback(credentials.ownerKey, id) : this.queue.removePlayback(credentials.ownerKey, id) }
    }
  }
  private async flushErrors(credentials: AnalyticsCredentials): Promise<void> {
    for (const item of this.queue.dueErrors(credentials.ownerKey, 20)) {
      const id = new Set([item.event_id])
      try {
        await this.request('POST', `api/v4/analytic/playback/${encodeURIComponent(item.session_id)}/errors`, item.event, credentials, item.platform)
        this.queue.removeErrors(credentials.ownerKey, id)
      } catch (error) {
        this.shouldRetry(error) ? this.queue.deferErrors(credentials.ownerKey, id) : this.queue.removeErrors(credentials.ownerKey, id)
        throw error
      }
    }
  }
  private async flushEvents(credentials: AnalyticsCredentials): Promise<void> {
    const pending = this.queue.dueEvents(credentials.ownerKey, 50); if (!pending.length) return
    const groups = new Map<string, PendingProductEvent[]>()
    for (const item of pending) { const key = JSON.stringify(item.context); groups.set(key, [...(groups.get(key) ?? []), item]) }
    for (const group of groups.values()) {
      const ids = new Set(group.map((i) => i.event_id))
      try { await this.sendEventGroup(group, credentials); this.queue.removeEvents(credentials.ownerKey, ids) }
      catch (error) {
        if (error instanceof AnalyticsHttpError && [413, 422].includes(error.status) && group.length > 1) { await this.isolateEvents(group, credentials); continue }
        this.shouldRetry(error) ? this.queue.deferEvents(credentials.ownerKey, ids) : this.queue.removeEvents(credentials.ownerKey, ids)
        throw error
      }
    }
  }
  private async isolateEvents(items: PendingProductEvent[], credentials: AnalyticsCredentials): Promise<void> {
    for (const item of items) {
      const id = new Set([item.event_id])
      try { await this.sendEventGroup([item], credentials); this.queue.removeEvents(credentials.ownerKey, id) }
      catch (error) { this.shouldRetry(error) ? this.queue.deferEvents(credentials.ownerKey, id) : this.queue.removeEvents(credentials.ownerKey, id) }
    }
  }
  private sendSinglePlayback(item: PendingPlayback, credentials: AnalyticsCredentials): Promise<unknown> {
    return this.request('PUT', `api/v4/analytic/playback/${encodeURIComponent(item.session_id)}`, item.payload, credentials, item.payload.context.platform)
  }
  private sendPlaybackBatch(items: PendingPlayback[], credentials: AnalyticsCredentials): Promise<unknown> {
    return this.request('POST', 'api/v4/analytic/playback/batch', {
      schema_version: 1, sessions: items.map((i) => ({ session_id: i.session_id, summary: i.payload })),
    }, credentials, items[0]!.payload.context.platform)
  }
  private sendEventGroup(items: PendingProductEvent[], credentials: AnalyticsCredentials): Promise<unknown> {
    return this.request('POST', 'api/v4/analytic/events/batch', {
      schema_version: 1, events: items.map((i) => i.event), context: items[0]!.context,
    }, credentials, items[0]!.context.platform)
  }
  private shouldRetry(error: unknown): boolean {
    return !(error instanceof AnalyticsHttpError) || error.retryable || error.status === 401
  }
  private endpoint(path: string): string {
    const base = this.configuration.baseUrl.replace(/\/+$/, '')
    const next = base.endsWith('/api/v4') ? path.replace(/^\/?api\/v4\//, '') : path.replace(/^\/+/, '')
    return `${base}/${next}`
  }
  private async request(method: 'GET' | 'POST' | 'PUT', path: string, body: unknown, credentials: AnalyticsCredentials, platform: AnalyticsPlatform): Promise<unknown> {
    if (!this.collectionEnabled) return null
    const json = body === null ? '' : JSON.stringify(body)
    if (new Blob([json]).size > (path.endsWith('/batch') ? 262_144 : 65_536)) throw new AnalyticsHttpError(413, false)
    const controller = typeof AbortController === 'undefined' ? null : new AbortController()
    const timeout = controller ? setTimeout(() => controller.abort(), this.configuration.requestTimeoutMs) : undefined
    try {
      const response = await this.configuration.fetcher(this.endpoint(path), {
        method, headers: {
          Accept: 'application/json', ...(body === null ? {} : { 'Content-Type': 'application/json' }),
          Authorization: `Bearer ${credentials.accessToken}`, 'Device-Token': credentials.deviceToken,
          'X-Analytics-SDK-Version': ANALYTICS_SDK_VERSION, 'X-Platform': platform,
        }, ...(body === null ? {} : { body: json }), signal: controller?.signal,
      })
      const text = await response.text(); const payload = text ? this.json(text) : null
      if (!response.ok) {
        const code = this.errorCode(payload)
        const retryable = code === 'ANALYTICS_COLLECTION_DISABLED' || [404, 408, 429].includes(response.status) || response.status >= 500
        throw new AnalyticsHttpError(response.status, retryable)
      }
      return payload
    } finally { if (timeout !== undefined) clearTimeout(timeout) }
  }
  private json(value: string): unknown { try { return JSON.parse(value) } catch { return value } }
  private errorCode(payload: unknown): string {
    if (!payload || typeof payload !== 'object') return ''
    const p = payload as { code?: unknown; error?: { code?: unknown } }; return String(p.error?.code ?? p.code ?? '')
  }
}
