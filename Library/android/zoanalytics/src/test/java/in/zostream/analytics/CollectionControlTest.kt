package `in`.zostream.analytics

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment

@RunWith(RobolectricTestRunner::class)
class CollectionControlTest {
    @Test
    fun firebaseKillSwitchUpdatesClientState() {
        val client = ZoAnalyticsClient(
            RuntimeEnvironment.getApplication(),
            collectionEnabled = false,
        )

        assertFalse(client.isCollectionEnabled())
        client.setCollectionEnabled(true)
        assertTrue(client.isCollectionEnabled())
        client.setCollectionEnabled(false)
        assertFalse(client.isCollectionEnabled())
    }
}
