# Chat reply and reaction notifications

Global, movie, episode and private chats notify the original message author when
someone replies or adds a reaction. Self-interactions do not notify. The title
identifies the chat and whether the activity is a Reply or Reaction; the body
names the actor and includes the reaction emoji. Message text and spoilers are
not included in interaction previews.

The worker checks current room visibility before sending. Users viewing that
room receive the live update without a push. Socket connection alone does not
suppress notifications. Delivery also rechecks blocks in both directions, bans,
message deletion, reaction removal, and private-room activity/membership. Reply
pushes are suppressed after the reply has been read. Reading the original message
does not suppress a later reaction. Pending interaction pushes expire after five
minutes.

Retries of the same reply or reaction do not enqueue duplicates. Removing and
re-adding the same emoji by the same person does not send another push. Private
replies replace the ordinary message push to the parent author; other private
members keep receiving normal message pushes.

## Deployment

Deploy backend code, then migrate the chat database before serving interaction
requests or starting the updated delivery workers:

```sh
php artisan chat:migrate --force
```

The migration `2026_10_31_000001_add_chat_interaction_notifications` adds nullable
notification payload/deduplication fields to `chat_outbox`. No main database
migration is needed. Restart every existing `chat:deliver` worker after migrating.
The push lane handles both `message_push` and `interaction_push`; realtime workers
exclude both kinds. Delivery remains asynchronous and never waits for FCM in the
message/reaction HTTP request.

Release the updated iOS and Android clients for public-chat notification routing.
Private activities use the existing `chat_message` notification key. Public
activities use `chat_activity`: Global opens Global Chat; movie and episode
activities open the matching title details (playback authorization is still
required to enter the content chat). Episode destinations resolve the parent
series public ID. Payloads include room/message/activity IDs; opening a push does
not currently scroll directly to that message.

Device notification permission and a registered push token remain required.
