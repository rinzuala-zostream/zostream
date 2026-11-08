import { ZoAnalyticsClient } from './client.js'
import type { AnalyticsClientConfiguration } from './client.js'
import { createProductEvent, createUuid } from './models.js'
import type { AnalyticsContext, AnalyticsCredentials, AnalyticsProperty, ProductEventName } from './models.js'

export interface AnalyticsRuntimeConfiguration {
  automaticLifecycleTracking: boolean
  presenceHeartbeatIntervalMs: number
}
export interface AnalyticsRuntimeOptions {
  configuration?: Partial<AnalyticsRuntimeConfiguration>
  collectionEnabled: () => boolean
  credentials: () => AnalyticsCredentials | null
  context: () => AnalyticsContext
}

export class ZoAnalytics {
  readonly client: ZoAnalyticsClient
  appSessionId = createUuid()
  private options: AnalyticsRuntimeOptions | null = null
  private runtimeConfiguration: AnalyticsRuntimeConfiguration = {
    automaticLifecycleTracking: true, presenceHeartbeatIntervalMs: 60_000,
  }
  private startedAt = Date.now()
  private recordedOpen = false
  private enteredBackground = false
  private presenceTimer: ReturnType<typeof setTimeout> | undefined
  private listeners: Array<() => void> = []

  constructor(clientConfiguration: Partial<AnalyticsClientConfiguration> = {}) {
    this.client = new ZoAnalyticsClient(clientConfiguration)
  }

  start(options: AnalyticsRuntimeOptions): void {
    this.stop(false); this.options = options
    this.runtimeConfiguration = { ...this.runtimeConfiguration, ...options.configuration }
    this.appSessionId = createUuid(); this.startedAt = Date.now(); this.recordedOpen = false
    this.enteredBackground = typeof document !== 'undefined' && document.visibilityState !== 'visible'
    this.refreshState(); this.recordOpen(); this.flush()
    if (this.runtimeConfiguration.automaticLifecycleTracking) this.installLifecycle()
    this.startPresence()
  }

  get isCollectionEnabled(): boolean { return this.options?.collectionEnabled() ?? false }
  credentialsDidChange(): void { this.refreshState(); this.recordOpen(); this.flush(); this.startPresence() }
  collectionStateDidChange(): void {
    this.refreshState()
    if (this.isCollectionEnabled) { this.recordOpen(); this.flush(); this.startPresence() }
    else this.stopPresence(false)
  }
  track(name: ProductEventName, properties: Record<string, AnalyticsProperty> = {}, flushImmediately = false): void {
    this.refreshState(); if (!this.isCollectionEnabled) return
    const credentials = this.options?.credentials(); const context = this.options?.context()
    if (!credentials || !context) return
    const event = createProductEvent(name, this.appSessionId, properties)
    void this.client.track(event, context, credentials, flushImmediately).catch(() => undefined)
  }
  screenViewed(name: string): void { this.track('screen_viewed', { screen_name: name.slice(0, 100) }) }
  flush(): void {
    this.refreshState(); const credentials = this.options?.credentials()
    if (this.isCollectionEnabled && credentials) void this.client.flush(credentials).catch(() => undefined)
  }
  stop(recordSessionEnd = true): void {
    if (recordSessionEnd && this.options) this.track('app_session_ended', { session_duration_ms: Math.max(0, Date.now() - this.startedAt) }, true)
    this.stopPresence(true); this.listeners.splice(0).forEach((remove) => remove())
    this.options = null; this.recordedOpen = false
  }

  private refreshState(): void { this.client.setCollectionEnabled(this.isCollectionEnabled) }
  private recordOpen(): void {
    if (!this.isCollectionEnabled || this.recordedOpen || !this.options?.credentials()) return
    this.recordedOpen = true; this.track('app_opened', {}, true)
  }
  private installLifecycle(): void {
    if (typeof document === 'undefined' || typeof window === 'undefined') return
    const visibility = () => {
      if (document.visibilityState === 'visible') {
        this.refreshState(); this.recordOpen()
        if (this.enteredBackground) { this.enteredBackground = false; this.track('app_foregrounded', {}, true) }
        this.flush(); this.startPresence()
      } else {
        this.enteredBackground = true; this.stopPresence(true)
        this.track('app_backgrounded', { session_duration_ms: Math.max(0, Date.now() - this.startedAt) }, true)
      }
    }
    const online = () => { this.flush(); this.startPresence() }
    const unload = () => {
      this.stopPresence(true)
      this.track('app_session_ended', { session_duration_ms: Math.max(0, Date.now() - this.startedAt) }, true)
    }
    document.addEventListener('visibilitychange', visibility); window.addEventListener('online', online); window.addEventListener('beforeunload', unload)
    this.listeners.push(
      () => document.removeEventListener('visibilitychange', visibility),
      () => window.removeEventListener('online', online),
      () => window.removeEventListener('beforeunload', unload),
    )
  }
  private startPresence(): void {
    if (!this.options || !this.isCollectionEnabled || !this.options.credentials()) return
    if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return
    this.stopPresence(false)
    const send = () => {
      const credentials = this.options?.credentials(); const context = this.options?.context()
      if (!this.isCollectionEnabled || !credentials || !context) return
      void this.client.updatePresence('foreground', context, credentials)
      const base = Math.max(30_000, this.runtimeConfiguration.presenceHeartbeatIntervalMs)
      const jitter = Math.min(10_000, base / 6)
      this.presenceTimer = setTimeout(send, Math.max(30_000, base + (Math.random() * 2 - 1) * jitter))
    }
    send()
  }
  private stopPresence(sendOffline: boolean): void {
    if (this.presenceTimer !== undefined) clearTimeout(this.presenceTimer)
    this.presenceTimer = undefined
    if (!sendOffline || !this.isCollectionEnabled) return
    const credentials = this.options?.credentials(); const context = this.options?.context()
    if (credentials && context) void this.client.updatePresence('background', context, credentials)
  }
}
