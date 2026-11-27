# Chat message write contract

The iOS room-open flow uses `POST /api/v4/chat/rooms/join`. Global, movie, and episode rooms are provisioned in RTDB, and the authenticated ZoStream user UID is added as a member. iOS does not authenticate to Firebase. It reads transcript and sync data through the bearer-authenticated backend endpoints `GET /api/v4/chat/rooms/{roomId}/messages` and `GET /api/v4/chat/rooms/{roomId}/sync`; Laravel reads RTDB with its service account and checks the user's room membership before returning data.

`POST /api/v4/chat/rooms/{roomId}/messages` is the authenticated write path used by iOS. It requires the normal ZoStream bearer session and accepts the message fields emitted by the existing iOS chat UI (`body`, `request_id`, `spoiler`, optional reply, moment, suggestion, room invitation, and watch data).

## RTDB room authorization

The backend treats `chat_v1/rooms/{roomId}/state` and `chat_v1/rooms/{roomId}/members` as server-owned authorization data. The state node must have `active: true` and `can_send: true`. A sender must have an entry at `members/{userUid}` with `active: true` and `can_send: true`. The API resolves `userUid` from the authenticated SQL user row; the client cannot supply it. Active member keys are ZoStream user UIDs and use the same shape for fan-out:

```json
{
  "state": { "active": true, "can_send": true },
  "members": {
    "zostream-user-uid": { "active": true, "can_send": true }
  }
}
```

If a room or membership is absent, sending is denied. The room provisioning/join flow must maintain these nodes before a user can send.

## Writes

After authorization, one RTDB multi-location update writes the canonical record at `chat_v1/rooms/{roomId}/messages/{messageId}`, the idempotency mapping at `chat_v1/room_requests/{roomId}/{userId}/{requestId}`, the latest message id, and each active participant's `user_messages` projection and `user_sync_events` event. This matches the existing `ChatFirebaseStream` record and event shape.

The iOS client has no direct RTDB read or write access. The Firebase service account is configured using `FIREBASE_DATABASE_URL` and the existing `firebase.credentials` setting. RTDB security rules can continue to deny all client access to chat paths.
