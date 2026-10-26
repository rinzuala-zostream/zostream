package `in`.zostream.analytics

import android.view.View
import android.view.ViewTreeObserver
import android.widget.ListView

/** Position a newly replaced transcript before exposing it or acknowledging visible messages. */
object ChatListPositioner {
    fun position(list: ListView, target: Int, alignTop: Boolean, isCurrent: () -> Boolean, positioned: (Boolean) -> Unit) {
        list.post {
            if (!isCurrent() || target !in 0 until list.count) return@post
            val row = list.adapter.getView(target, null, list)
            row.measure(View.MeasureSpec.makeMeasureSpec((list.width - list.paddingLeft - list.paddingRight).coerceAtLeast(1), View.MeasureSpec.EXACTLY),
                View.MeasureSpec.makeMeasureSpec(0, View.MeasureSpec.UNSPECIFIED))
            // setSelectionFromTop already adds the ListView's top padding.
            val offset = if (alignTop) 0 else list.height - list.paddingBottom - list.paddingTop - row.measuredHeight
            val listener = object : ViewTreeObserver.OnPreDrawListener {
                private var attempts = 0
                override fun onPreDraw(): Boolean {
                    if (!isCurrent()) {
                        if (list.viewTreeObserver.isAlive) list.viewTreeObserver.removeOnPreDrawListener(this)
                        return true
                    }
                    val child = list.getChildAt(target - list.firstVisiblePosition)
                    val placed = child != null && if (alignTop) {
                        child.top >= list.paddingTop - 2 && child.top < list.height - list.paddingBottom
                    } else {
                        list.lastVisiblePosition == target && child.bottom <= list.height - list.paddingBottom + 2
                    }
                    if (!placed && ++attempts < 6) {
                        list.setSelectionFromTop(target, offset)
                        return false
                    }
                    list.viewTreeObserver.removeOnPreDrawListener(this)
                    positioned(placed)
                    return true
                }
            }
            list.viewTreeObserver.addOnPreDrawListener(listener)
            list.setSelectionFromTop(target, offset)
        }
    }
}
