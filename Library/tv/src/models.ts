export const ANALYTICS_SDK_VERSION = '1.4.0'

export type PlaybackContentType = 'movie' | 'episode' | 'live'
export type PlaybackUploadState = 'checkpoint' | 'final'
export type PlaybackEndReason =
  | 'completed' | 'user_closed' | 'back_pressed' | 'content_changed'
  | 'next_episode' | 'app_backgrounded' | 'app_terminated'
  | 'playback_error' | 'network_lost' | 'subscription_expired'
  | 'player_destroyed' | 'unknown'
export type NetworkType = 'wifi' | 'cellular' | 'ethernet' | 'offline' | 'unknown'
export type PresenceState = 'foreground' | 'background'

export interface AnalyticsCredentials {
  accessToken: string
  deviceToken: string
  ownerKey: string
}

export interface PlaybackContent {
  id: string
  type: PlaybackContentType
  series_id: string | null
  season_id: string | null
  episode_id: string | null
  is_downloaded: boolean
  autoplay: boolean
}

export interface AnalyticsContext {
  platform: 'tv'
  network_type: NetworkType
  app_version: string
  build_number: string
  os_version: string
  device_model: string
  device_category: 'tv'
  locale: string
  timezone: string
}

export interface PlaybackSummaryPayload {
  schema_version: 1
  revision: number
  state: PlaybackUploadState
  started_at: string
  ended_at: string | null
  end_reason: PlaybackEndReason | null
  content: PlaybackContent
  timing: {
    duration_ms: number; watch_position_ms: number; max_position_ms: number
    watched_ms: number; unique_watched_ms: number; replayed_ms: number
    foreground_watch_ms: number; background_play_ms: number; startup_ms: number | null
  }
  interaction: {
    play_count: number; pause_count: number; resume_count: number
    seek_count: number; seek_forward_ms: number; seek_backward_ms: number
    fullscreen_count: number; pip_count: number; cast_count: number
  }
  buffering: { count: number; total_ms: number; longest_ms: number }
  quality: {
    initial: string | null; final: string | null; change_count: number
    average_bitrate_kbps: number | null; bytes_transferred: number | null
    dropped_frames: number | null; rendered_frames: number | null
    video_codec: string | null; audio_codec: string | null; stream_format: string | null
  }
  tracks: {
    audio_language: string | null; subtitle_enabled: boolean
    subtitle_language: string | null; playback_speed: number
  }
  result: {
    completed: boolean; completion_percent: number
    milestones: Array<25 | 50 | 75 | 90>; error_count: number
  }
  context: AnalyticsContext
}

export interface PlaybackSummary {
  sessionId: string
  revision: number
  state: PlaybackUploadState
  payload: PlaybackSummaryPayload
}

export const supportedProductEventNames = [
  'app_opened', 'app_foregrounded', 'app_backgrounded', 'app_session_ended',
  'screen_viewed', 'content_impression', 'content_opened', 'search_performed',
  'search_result_selected', 'search_empty', 'wishlist_added', 'wishlist_removed',
  'download_started', 'download_completed', 'download_failed', 'notification_opened',
  'paywall_viewed', 'plan_selected', 'purchase_started', 'purchase_completed',
  'purchase_failed', 'purchase_cancelled', 'restore_purchase_completed',
] as const

export type ProductEventName = typeof supportedProductEventNames[number]
export type AnalyticsProperty = string | number | boolean | null | readonly unknown[]

export interface ProductEvent {
  event_id: string
  name: ProductEventName
  occurred_at: string
  app_session_id: string
  properties: Record<string, AnalyticsProperty>
}

export interface PlaybackErrorEvent {
  schema_version: 1; event_id: string; occurred_at: string; position_ms: number
  category: string; stage: string; code: string; http_status: number | null
  is_fatal: boolean; is_retryable: boolean; retry_count: number
  network_type: NetworkType; sanitized_message: string | null
}

export interface RemoteAnalyticsConfiguration {
  enabled: boolean; schema_version: number; minimum_sdk_version: string
  checkpoint_upload_enabled: boolean; local_snapshot_interval_seconds: number
  max_pending_sessions: number; pending_retention_days: number
  max_batch_size: number; max_payload_bytes: number; sample_rate: number
  presence_heartbeat_enabled: boolean; presence_heartbeat_interval_seconds: number
  presence_ttl_seconds: number
}

export function createUuid(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID().toLowerCase()
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (value) => {
    const random = Math.floor(Math.random() * 16)
    return (value === 'x' ? random : (random & 3) | 8).toString(16)
  })
}

export function createProductEvent(
  name: ProductEventName,
  appSessionId: string,
  properties: Record<string, AnalyticsProperty> = {},
): ProductEvent {
  const cleaned: Record<string, AnalyticsProperty> = {}
  Object.entries(properties).forEach(([key, value]) => {
    if (key.length > 64) return
    cleaned[key] = typeof value === 'string'
      ? value.slice(0, 255)
      : Array.isArray(value) ? value.slice(0, 20) : value
  })
  return {
    event_id: createUuid(), name, occurred_at: new Date().toISOString(),
    app_session_id: appSessionId, properties: cleaned,
  }
}

export function createPlaybackErrorEvent(input: {
  positionMs: number; category: string; stage: string; code: string
  isFatal: boolean; isRetryable: boolean; httpStatus?: number | null
  retryCount?: number; networkType?: NetworkType; sanitizedMessage?: string | null
}): PlaybackErrorEvent {
  return {
    schema_version: 1, event_id: createUuid(), occurred_at: new Date().toISOString(),
    position_ms: Math.max(0, Math.round(input.positionMs)),
    category: input.category.slice(0, 64), stage: input.stage.slice(0, 64),
    code: input.code.slice(0, 128), http_status: input.httpStatus ?? null,
    is_fatal: input.isFatal, is_retryable: input.isRetryable,
    retry_count: Math.max(0, Math.round(input.retryCount ?? 0)),
    network_type: input.networkType ?? 'unknown',
    sanitized_message: input.sanitizedMessage?.slice(0, 500) ?? null,
  }
}
