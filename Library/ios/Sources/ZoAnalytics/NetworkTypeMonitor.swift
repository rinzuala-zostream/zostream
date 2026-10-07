import Foundation
import Network

/// Tracks the device's active network path without requiring app-specific permissions.
final class NetworkTypeMonitor: @unchecked Sendable {
    static let shared = NetworkTypeMonitor()

    private let lock = NSLock()
    private let monitor = NWPathMonitor()
    private let queue = DispatchQueue(label: "in.zostream.analytics.network")
    private var started = false
    private var value: NetworkType = .unknown

    private init() {}

    var current: NetworkType {
        lock.lock()
        defer { lock.unlock() }
        return value
    }

    func start() {
        lock.lock()
        guard !started else {
            lock.unlock()
            return
        }
        started = true
        lock.unlock()

        monitor.pathUpdateHandler = { [weak self] path in
            guard let self else { return }
            let type: NetworkType
            if path.status != .satisfied {
                type = .offline
            } else if path.usesInterfaceType(.wifi) {
                type = .wifi
            } else if path.usesInterfaceType(.cellular) {
                type = .cellular
            } else if path.usesInterfaceType(.wiredEthernet) {
                type = .ethernet
            } else {
                type = .unknown
            }
            self.lock.lock()
            self.value = type
            self.lock.unlock()
        }
        monitor.start(queue: queue)
    }
}
