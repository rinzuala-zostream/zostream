import type { AnalyticsContext, PlaybackErrorEvent, PlaybackSummary, ProductEvent } from './models.js'

export interface AnalyticsStorage {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
  removeItem(key: string): void
}

type PendingBase = { owner_key: string; queued_at: number; attempt_count: number; next_attempt_at: number }
export type PendingPlayback = PendingBase & {
  session_id: string; revision: number; state: string; payload: PlaybackSummary['payload']
}
export type PendingProductEvent = PendingBase & {
  event_id: string; event: ProductEvent; context: AnalyticsContext
}
export type PendingPlaybackError = PendingBase & {
  event_id: string; session_id: string; platform: 'tv'; event: PlaybackErrorEvent
}
type QueueDocument = { playback: PendingPlayback[]; events: PendingProductEvent[]; errors: PendingPlaybackError[] }

const QUEUE_KEY = 'zoanalytics_queue_v1'
const delays = [30_000, 120_000, 600_000, 3_600_000, 21_600_000]
const empty = (): QueueDocument => ({ playback: [], events: [], errors: [] })

export class MemoryAnalyticsStorage implements AnalyticsStorage {
  private values = new Map<string, string>()
  getItem(key: string): string | null { return this.values.get(key) ?? null }
  setItem(key: string, value: string): void { this.values.set(key, value) }
  removeItem(key: string): void { this.values.delete(key) }
}

export function browserAnalyticsStorage(): AnalyticsStorage {
  try {
    if (typeof localStorage !== 'undefined') return localStorage
  } catch { /* TV private mode can deny localStorage. */ }
  return new MemoryAnalyticsStorage()
}

export class AnalyticsQueue {
  constructor(
    private readonly storage: AnalyticsStorage,
    private readonly maxPendingSessions = 500,
    private readonly retentionDays = 7,
  ) {}

  upsertPlayback(summary: PlaybackSummary, ownerKey: string, now = Date.now()): void {
    const doc = this.pruned(now)
    const index = doc.playback.findIndex((item) => item.owner_key === ownerKey && item.session_id === summary.sessionId)
    const pending: PendingPlayback = {
      owner_key: ownerKey, session_id: summary.sessionId, revision: summary.revision,
      state: summary.state, payload: summary.payload, queued_at: now,
      attempt_count: 0, next_attempt_at: now,
    }
    if (index >= 0) {
      const existing = doc.playback[index]
      if (existing && existing.revision <= summary.revision) doc.playback[index] = pending
    } else doc.playback.push(pending)
    doc.playback = doc.playback.slice(-this.maxPendingSessions); this.save(doc)
  }

  enqueueEvent(event: ProductEvent, context: AnalyticsContext, ownerKey: string, now = Date.now()): void {
    const doc = this.pruned(now)
    if (doc.events.some((item) => item.event_id === event.event_id)) return
    doc.events.push({ owner_key: ownerKey, event_id: event.event_id, event, context, queued_at: now, attempt_count: 0, next_attempt_at: now })
    doc.events = doc.events.slice(-(this.maxPendingSessions * 10)); this.save(doc)
  }

  enqueueError(event: PlaybackErrorEvent, sessionId: string, ownerKey: string, now = Date.now()): void {
    const doc = this.pruned(now)
    if (doc.errors.some((item) => item.event_id === event.event_id)) return
    doc.errors.push({ owner_key: ownerKey, session_id: sessionId, event_id: event.event_id, platform: 'tv', event, queued_at: now, attempt_count: 0, next_attempt_at: now })
    doc.errors = doc.errors.slice(-this.maxPendingSessions); this.save(doc)
  }

  duePlayback(owner: string, limit: number, now = Date.now()): PendingPlayback[] { return this.due(this.pruned(now).playback, owner, limit, now) }
  dueEvents(owner: string, limit: number, now = Date.now()): PendingProductEvent[] { return this.due(this.pruned(now).events, owner, limit, now) }
  dueErrors(owner: string, limit: number, now = Date.now()): PendingPlaybackError[] { return this.due(this.pruned(now).errors, owner, limit, now) }
  removePlayback(owner: string, ids: Set<string>): void { const d = this.load(); d.playback = d.playback.filter((i) => i.owner_key !== owner || !ids.has(i.session_id)); this.save(d) }
  removeEvents(owner: string, ids: Set<string>): void { const d = this.load(); d.events = d.events.filter((i) => i.owner_key !== owner || !ids.has(i.event_id)); this.save(d) }
  removeErrors(owner: string, ids: Set<string>): void { const d = this.load(); d.errors = d.errors.filter((i) => i.owner_key !== owner || !ids.has(i.event_id)); this.save(d) }
  deferPlayback(owner: string, ids: Set<string>): void { this.defer('playback', owner, 'session_id', ids) }
  deferEvents(owner: string, ids: Set<string>): void { this.defer('events', owner, 'event_id', ids) }
  deferErrors(owner: string, ids: Set<string>): void { this.defer('errors', owner, 'event_id', ids) }
  clear(owner: string): void {
    const d = this.load(); d.playback = d.playback.filter((i) => i.owner_key !== owner)
    d.events = d.events.filter((i) => i.owner_key !== owner); d.errors = d.errors.filter((i) => i.owner_key !== owner); this.save(d)
  }
  counts(owner: string): { playback: number; events: number; errors: number } {
    const d = this.load(); return {
      playback: d.playback.filter((i) => i.owner_key === owner).length,
      events: d.events.filter((i) => i.owner_key === owner).length,
      errors: d.errors.filter((i) => i.owner_key === owner).length,
    }
  }

  private due<T extends PendingBase>(items: T[], owner: string, limit: number, now: number): T[] {
    return items.filter((i) => i.owner_key === owner && i.next_attempt_at <= now).sort((a, b) => a.queued_at - b.queued_at).slice(0, limit)
  }
  private defer(collection: keyof QueueDocument, owner: string, idKey: 'session_id' | 'event_id', ids: Set<string>): void {
    const doc = this.load(); const now = Date.now()
    for (const item of doc[collection]) {
      const id = idKey === 'session_id' && 'session_id' in item ? item.session_id : 'event_id' in item ? item.event_id : ''
      if (item.owner_key !== owner || !ids.has(id)) continue
      item.attempt_count += 1
      const base = delays[Math.min(delays.length - 1, item.attempt_count - 1)] ?? delays[delays.length - 1] ?? 30_000
      item.next_attempt_at = now + base + Math.floor(Math.random() * Math.max(1, base / 5))
    }
    this.save(doc)
  }
  private pruned(now: number): QueueDocument {
    const d = this.load(); const cutoff = now - this.retentionDays * 86_400_000
    d.playback = d.playback.filter((i) => i.queued_at >= cutoff)
    d.events = d.events.filter((i) => i.queued_at >= cutoff)
    d.errors = d.errors.filter((i) => i.queued_at >= cutoff); this.save(d); return d
  }
  private load(): QueueDocument {
    try {
      const raw = this.storage.getItem(QUEUE_KEY); if (!raw) return empty()
      const value = JSON.parse(raw) as Partial<QueueDocument>
      return { playback: Array.isArray(value.playback) ? value.playback : [], events: Array.isArray(value.events) ? value.events : [], errors: Array.isArray(value.errors) ? value.errors : [] }
    } catch { return empty() }
  }
  private save(doc: QueueDocument): void {
    try { this.storage.setItem(QUEUE_KEY, JSON.stringify(doc)) } catch { /* Analytics never blocks playback. */ }
  }
}
