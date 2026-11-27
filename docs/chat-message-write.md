# Chat message write contract

The iOS room-open flow uses `POST /api/v4/chat/rooms/join`. Global, movie, and episode rooms are provisioned in RTDB, and the authenticated Firebase UID is added as a member. The room response selects the Firebase realtime transport. iOS then obtains a Firebase custom token from `POST /api/v4/chat/firebase/token`, renews its room lease at `POST /api/v4/chat/firebase/lease`, and recovers events through `GET /api/v4/chat/rooms/{roomId}/sync`. Transcript reads use `GET /api/v4/chat/rooms/{roomId}/messages`.

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

Firebase custom tokens authenticate with the SQL user's stored Firebase UID. RTDB rules permit that authenticated UID to read only its own `user_messages`, `user_sync_events`, `user_account_events`, and `user_typing` projections. Client writes remain denied. The Firebase service account is configured using `FIREBASE_DATABASE_URL` and the existing `firebase.credentials` setting.
