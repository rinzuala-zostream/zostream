import type { AnalyticsContext, NetworkType, TVWebAnalyticsPlatform } from './models.js'

interface NetworkInformation { type?: string; effectiveType?: string }
interface TVWindow extends Window { tizen?: unknown; webOS?: unknown; PalmSystem?: unknown }

export function detectTVNetworkType(): NetworkType {
  if (typeof navigator === 'undefined') return 'unknown'
  if (navigator.onLine === false) return 'offline'
  const nav = navigator as Navigator & { connection?: NetworkInformation; mozConnection?: NetworkInformation; webkitConnection?: NetworkInformation }
  const connection = nav.connection ?? nav.mozConnection ?? nav.webkitConnection
  const value = `${connection?.type ?? ''} ${connection?.effectiveType ?? ''}`.toLowerCase()
  if (value.includes('ethernet')) return 'ethernet'
  if (value.includes('wifi')) return 'wifi'
  if (/cellular|2g|3g|4g|5g/.test(value)) return 'cellular'
  return 'unknown'
}

export function createTVAnalyticsContext(input: {
  appVersion: string; buildNumber: string; networkType?: NetworkType
  platform?: TVWebAnalyticsPlatform
  osVersion?: string; deviceModel?: string; locale?: string; timezone?: string
}): AnalyticsContext {
  const win = typeof window === 'undefined' ? undefined : window as TVWindow
  const nav = typeof navigator === 'undefined' ? undefined : navigator
  const userAgent = nav?.userAgent ?? 'unknown'
  const match = (pattern: RegExp) => userAgent.match(pattern)?.[1] ?? 'unknown'
  const detectedPlatform: TVWebAnalyticsPlatform = win?.tizen
    ? 'tizen'
    : win?.webOS || win?.PalmSystem ? 'webos' : 'web'
  const platformName = detectedPlatform === 'tizen'
    ? 'Samsung Tizen'
    : detectedPlatform === 'webos' ? 'LG webOS' : 'Web browser'
  const detectedOs = win?.tizen ? `Tizen ${match(/Tizen[\s\/]([\d.]+)/i)}`
    : win?.webOS || win?.PalmSystem ? `webOS ${match(/(?:webOS|Web0S)[\s\/]([\d.]+)/i)}`
      : nav?.platform ?? 'unknown'
  let timezone = input.timezone
  if (!timezone) { try { timezone = Intl.DateTimeFormat().resolvedOptions().timeZone } catch { timezone = 'unknown' } }
  return {
    platform: input.platform ?? detectedPlatform,
    network_type: input.networkType ?? detectTVNetworkType(),
    app_version: input.appVersion.slice(0, 64), build_number: input.buildNumber.slice(0, 64),
    os_version: (input.osVersion ?? detectedOs).slice(0, 128),
    device_model: (input.deviceModel ?? `${platformName} ${userAgent}`).slice(0, 128),
    device_category: 'tv', locale: (input.locale ?? nav?.language ?? 'unknown').slice(0, 32),
    timezone: (timezone ?? 'unknown').slice(0, 64),
  }
}
