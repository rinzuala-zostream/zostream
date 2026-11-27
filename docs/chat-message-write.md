# Chat message write contract

`POST /api/v4/chat/rooms/{roomId}/messages` is the authenticated write path used by iOS. It requires the normal ZoStream bearer session and accepts the message fields emitted by the existing iOS chat UI (`body`, `request_id`, `spoiler`, optional reply, moment, suggestion, room invitation, and watch data).

## RTDB room authorization

The backend treats `chat_v1/rooms/{roomId}/state` and `chat_v1/rooms/{roomId}/members` as server-owned authorization data. The state node must have `active: true` and `can_send: true`. A sender must have an entry at `members/{firebaseUid}` with `active: true` and `can_send: true`. The API resolves the Firebase UID from the authenticated SQL user row; the client cannot supply it. Active member keys are Firebase UIDs and use the same shape for fan-out:

```json
{
  "state": { "active": true, "can_send": true },
  "members": {
    "firebase-user-uid": { "active": true, "can_send": true }
  }
}
```

If a room or membership is absent, sending is denied. The room provisioning/join flow must maintain these nodes before a user can send.

## Writes

After authorization, one RTDB multi-location update writes the canonical record at `chat_v1/rooms/{roomId}/messages/{messageId}`, the idempotency mapping at `chat_v1/room_requests/{roomId}/{userId}/{requestId}`, the latest message id, and each active participant's `user_messages` projection and `user_sync_events` event. This matches the existing `ChatFirebaseStream` record and event shape.

The Firebase service account is configured using `FIREBASE_DATABASE_URL` and the existing `firebase.credentials` setting. Direct client writes to chat remain denied by Firebase rules.
