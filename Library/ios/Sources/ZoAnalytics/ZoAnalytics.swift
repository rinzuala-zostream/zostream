import Foundation
#if canImport(UIKit)
import UIKit
#endif

/// Application-scoped facade. Start it once from the app entry point.
@MainActor
public final class ZoAnalytics {
    public static let shared = ZoAnalytics()

    public private(set) var client: ZoAnalyticsClient?
    public private(set) var appSessionId = UUID().uuidString.lowercased()

    private var credentialsProvider: (() -> AnalyticsCredentials?)?
    private var contextProvider: (() -> AnalyticsContext)?
    private var collectionEnabledProvider: (() -> Bool)?
    private var sessionStartedAt = Date()
    private var recordedOpen = false
    private var enteredBackground = false
    private var observers: [NSObjectProtocol] = []
    private var collectionUpdateTask: Task<Void, Never>?
    private var presenceTask: Task<Void, Never>?
    private var configuration = ZoAnalyticsConfiguration()

    private init() {}

    public func start(
        configuration: ZoAnalyticsConfiguration = .init(),
        collectionEnabled: @escaping () -> Bool = { false },
        credentials: @escaping () -> AnalyticsCredentials?,
        context: @escaping () -> AnalyticsContext
    ) {
        stop(recordSessionEnd: false)
        NetworkTypeMonitor.shared.start()
        self.configuration = configuration
        let initiallyEnabled = collectionEnabled()
        client = ZoAnalyticsClient(
            configuration: configuration,
            collectionEnabled: initiallyEnabled
        )
        collectionEnabledProvider = collectionEnabled
        credentialsProvider = credentials
        contextProvider = context
        appSessionId = UUID().uuidString.lowercased()
        sessionStartedAt = Date()
        enteredBackground = false
        recordOpenIfPossible()
        startPresenceHeartbeat()
        if configuration.automaticLifecycleTracking { installLifecycleObservers() }
    }

    /// Call after login or token refresh. Credentials are only read into memory for a request.
    public func credentialsDidChange() {
        guard isCollectionEnabled else { return }
        recordOpenIfPossible()
        flush()
        startPresenceHeartbeat()
    }

    /// Call whenever the host's remote collection flag changes.
    public func collectionStateDidChange() {
        guard let client else { return }
        let enabled = isCollectionEnabled
        let previousUpdate = collectionUpdateTask
        collectionUpdateTask = Task {
            await previousUpdate?.value
            await client.setCollectionEnabled(enabled)
            guard enabled else {
                await MainActor.run { self.stopPresenceHeartbeat(sendOffline: false) }
                return
            }
            await MainActor.run {
                guard self.client === client, self.isCollectionEnabled else { return }
                self.recordOpenIfPossible()
                self.flush()
                self.startPresenceHeartbeat()
            }
        }
    }

    public var isCollectionEnabled: Bool {
        collectionEnabledProvider?() ?? false
    }

    public func track(
        name: String,
        properties: [String: JSONValue] = [:],
        flushImmediately: Bool = false
    ) {
        guard ProductEvent.supportedNames.contains(name) else { return }
        guard isCollectionEnabled else { return }
        guard let client, let credentials = credentialsProvider?(), let context = contextProvider?().fillingDetectedNetworkType() else { return }
        let event = ProductEvent(
            name: name,
            appSessionId: appSessionId,
            properties: properties
        )
        Task { await client.track(event, context: context, credentials: credentials, flushImmediately: flushImmediately) }
    }

    /// Use a stable product name such as `home`, `search`, or `movie_detail`.
    public func screenViewed(_ name: String) {
        track(name: "screen_viewed", properties: ["screen_name": .string(String(name.prefix(100)))])
    }

    public func flush() {
        guard isCollectionEnabled else { return }
        guard let client, let credentials = credentialsProvider?() else { return }
        Task { try? await client.flush(credentials: credentials) }
    }

    public func stop(recordSessionEnd: Bool = true) {
        if recordSessionEnd, client != nil {
            trackLifecycle(
                name: "app_session_ended",
                properties: ["session_duration_ms": .integer(sessionDurationMs())]
            )
        }
        stopPresenceHeartbeat(sendOffline: true)
        observers.forEach(NotificationCenter.default.removeObserver)
        observers.removeAll()
        client = nil
        collectionUpdateTask = nil
        credentialsProvider = nil
        contextProvider = nil
        collectionEnabledProvider = nil
        recordedOpen = false
    }

    private func recordOpenIfPossible() {
        guard isCollectionEnabled, !recordedOpen, credentialsProvider?() != nil else { return }
        recordedOpen = true
        trackLifecycle(name: "app_opened")
    }

    private func sessionDurationMs() -> Int64 {
        Int64(max(0, Date().timeIntervalSince(sessionStartedAt) * 1_000))
    }

    private func installLifecycleObservers() {
#if canImport(UIKit)
        let center = NotificationCenter.default
        observers.append(center.addObserver(
            forName: UIApplication.didBecomeActiveNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor in
                guard let self else { return }
                self.recordOpenIfPossible()
                if self.enteredBackground {
                    self.enteredBackground = false
                    self.trackLifecycle(name: "app_foregrounded")
                }
                self.startPresenceHeartbeat()
            }
        })
        observers.append(center.addObserver(
            forName: UIApplication.didEnterBackgroundNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor in
                guard let self else { return }
                self.enteredBackground = true
                self.stopPresenceHeartbeat(sendOffline: true)
                self.trackLifecycle(
                    name: "app_backgrounded",
                    properties: ["session_duration_ms": .integer(self.sessionDurationMs())]
                )
            }
        })
#endif
    }

    private func trackLifecycle(
        name: String,
        properties: [String: JSONValue] = [:]
    ) {
        guard isCollectionEnabled else { return }
        guard let client, let credentials = credentialsProvider?(), let context = contextProvider?().fillingDetectedNetworkType() else { return }
        let event = ProductEvent(
            name: name,
            appSessionId: appSessionId,
            properties: properties
        )
        Task {
            await client.track(event, context: context, credentials: credentials)
            try? await client.flush(credentials: credentials)
        }
    }

    private func startPresenceHeartbeat() {
        guard configuration.automaticLifecycleTracking, canReportPresence,
              isCollectionEnabled, client != nil,
              credentialsProvider?() != nil, contextProvider != nil else { return }
        presenceTask?.cancel()
        let baseInterval = configuration.presenceHeartbeatInterval
        presenceTask = Task { [weak self] in
            while !Task.isCancelled {
                guard let self,
                      self.isCollectionEnabled,
                      let client = self.client,
                      let credentials = self.credentialsProvider?(),
                      let context = self.contextProvider?().fillingDetectedNetworkType() else { return }
                await client.updatePresence(.foreground, context: context, credentials: credentials)
                let jitter = Double.random(in: -10...10)
                let sleepNanoseconds = UInt64(max(30, baseInterval + jitter) * 1_000_000_000)
                try? await Task.sleep(nanoseconds: sleepNanoseconds)
            }
        }
    }

    private func stopPresenceHeartbeat(sendOffline: Bool) {
        presenceTask?.cancel()
        presenceTask = nil
        guard sendOffline, isCollectionEnabled,
              let client, let credentials = credentialsProvider?(),
              let context = contextProvider?().fillingDetectedNetworkType() else { return }
        Task { await client.updatePresence(.background, context: context, credentials: credentials) }
    }

    private var canReportPresence: Bool {
#if canImport(UIKit)
        UIApplication.shared.applicationState != .background
#else
        true
#endif
    }
}
