package `in`.zostream.analytics

import org.junit.Assert.assertEquals
import org.junit.Test

class ProductEventTest {
    @Test
    fun lifecycleEventsAreAcceptedByTheContract() {
        val names = listOf(
            "app_opened",
            "app_foregrounded",
            "app_backgrounded",
            "app_session_ended",
        )

        names.forEach { name ->
            assertEquals(name, ProductEvent(name, "session-1").name)
        }
    }
}
