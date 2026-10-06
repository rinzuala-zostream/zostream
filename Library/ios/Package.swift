// swift-tools-version: 5.9

import PackageDescription

let package = Package(
    name: "ZoAnalytics",
    platforms: [
        .iOS(.v15),
        .macOS(.v13)
    ],
    products: [
        .library(name: "ZoAnalytics", targets: ["ZoAnalytics"])
    ],
    targets: [
        .target(name: "ZoAnalytics"),
        .testTarget(name: "ZoAnalyticsTests", dependencies: ["ZoAnalytics"])
    ]
)
