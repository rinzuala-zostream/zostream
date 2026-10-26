package `in`.zostream.analytics

import android.app.Activity
import android.view.View
import android.view.ViewGroup
import android.widget.AbsListView
import android.widget.BaseAdapter
import android.widget.ListView
import android.widget.FrameLayout
import android.widget.TextView
import android.os.Looper
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.Robolectric
import org.robolectric.RobolectricTestRunner
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [28])
class ChatListPositionerTest {
    private fun withList(test: (ListView) -> Unit) {
        val controller = Robolectric.buildActivity(Activity::class.java).setup()
        val host = controller.get()
        val list = ListView(host).apply {
            setPadding(0, 12, 0, 12)
            setStackFromBottom(true)
            divider = null
            adapter = object : BaseAdapter() {
                override fun getCount() = 100
                override fun getItem(position: Int) = position
                override fun getItemId(position: Int) = position.toLong()
                override fun getView(position: Int, recycled: View?, parent: ViewGroup?) = TextView(host).apply {
                    text = "Message $position"
                    minHeight = if (position == 99) 360 else 80 + position % 3 * 10
                    layoutParams = AbsListView.LayoutParams(-1, -2)
                }
            }
        }
        host.setContentView(FrameLayout(host).apply {
            addView(list, FrameLayout.LayoutParams(320, 240))
        })
        try { layout(list); test(list) } finally { controller.pause().stop().destroy() }
    }
    private fun layout(list: ListView) {
        list.measure(View.MeasureSpec.makeMeasureSpec(320, View.MeasureSpec.EXACTLY),
            View.MeasureSpec.makeMeasureSpec(240, View.MeasureSpec.EXACTLY))
        list.layout(0, 0, 320, 240)
    }
    private fun settle(list: ListView) {
        repeat(10) {
            shadowOf(Looper.getMainLooper()).idle()
            layout(list)
            list.viewTreeObserver.dispatchOnPreDraw()
        }
    }
    @Test fun firstUnreadIsVisibleWithoutTouchingTheTranscript() = withList { list ->
        var positioned = false
        ChatListPositioner.position(list, 8, true, { true }) { positioned = it }
        settle(list)
        assertTrue(positioned)
        val target = list.getChildAt(8 - list.firstVisiblePosition)
        assertNotNull(target)
        assertTrue(target.top >= list.paddingTop)
    }
    @Test fun newestTallRowIsAnchoredToBottomWithoutDoubleCountingPadding() = withList { list ->
        var positioned = false
        ChatListPositioner.position(list, 99, false, { true }) { positioned = it }
        settle(list)
        assertTrue(positioned)
        assertEquals(99, list.lastVisiblePosition)
        assertTrue("Last row bottom=${list.getChildAt(list.childCount - 1).bottom}, viewport=${list.height}, padding=${list.paddingBottom}",
            list.getChildAt(list.childCount - 1).bottom <= list.height - list.paddingBottom + 2)
    }
    @Test fun queuedPositionForAnOldRoomDoesNotMoveTheNewRoom() = withList { list ->
        var current = true
        var callback = false
        val before = list.firstVisiblePosition
        ChatListPositioner.position(list, 8, true, { current }) { callback = true }
        current = false
        settle(list)
        assertFalse(callback)
        assertEquals(before, list.firstVisiblePosition)
    }
}
