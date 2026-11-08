package `in`.zostream.analytics

import org.junit.Assert.assertEquals
import org.junit.Test

class AnalyticsContextTest {
    @Test
    fun `device identity separates television from Android mobile`() {
        assertEquals(
            AnalyticsPlatform.TV to "tv",
            AnalyticsContext.deviceIdentity(isTelevision = true, smallestScreenWidthDp = 720),
        )
        assertEquals(
            AnalyticsPlatform.ANDROID to "phone",
            AnalyticsContext.deviceIdentity(isTelevision = false, smallestScreenWidthDp = 411),
        )
        assertEquals(
            AnalyticsPlatform.ANDROID to "tablet",
            AnalyticsContext.deviceIdentity(isTelevision = false, smallestScreenWidthDp = 800),
        )
    }
}
