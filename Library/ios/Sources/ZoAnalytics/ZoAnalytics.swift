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

    private init() {}

    public func start(
        configuration: ZoAnalyticsConfiguration = .init(),
        collectionEnabled: @escaping () -> Bool = { true },
        credentials: @escaping () -> AnalyticsCredentials?,
        context: @escaping () -> AnalyticsContext
    ) {
        stop(recordSessionEnd: false)
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
        if configuration.automaticLifecycleTracking { installLifecycleObservers() }
    }

    /// Call after login or token refresh. Credentials are only read into memory for a request.
    public func credentialsDidChange() {
        guard isCollectionEnabled else { return }
        recordOpenIfPossible()
        flush()
    }

    /// Call whenever the host's remote collection flag changes.
    public func collectionStateDidChange() {
        guard let client else { return }
        let enabled = isCollectionEnabled
        Task {
            await client.setCollectionEnabled(enabled)
            guard enabled else { return }
            await MainActor.run {
                self.recordOpenIfPossible()
                self.flush()
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
        guard let client, let credentials = credentialsProvider?(), let context = contextProvider?() else { return }
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
        observers.forEach(NotificationCenter.default.removeObserver)
        observers.removeAll()
        client = nil
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
        guard let client, let credentials = credentialsProvider?(), let context = contextProvider?() else { return }
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
}
