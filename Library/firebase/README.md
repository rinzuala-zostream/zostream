# ZoAnalytics Realtime Database switch

ZoAnalytics uses this Firebase Realtime Database value as its live collection
kill switch:

```text
/config/analytics/enabled
```

The value is a native Boolean. `false` prevents collection, local persistence,
and analytics API uploads. The admin Analytics page creates and updates the
node, so Remote Config setup and template uploads are not required. The
authenticated analytics config endpoint reads this same value, and ingestion
requests are rejected while it is false or Firebase cannot be reached.

## Initial data

The admin switch defaults to `false` when the node does not exist. Turning the
switch on or off creates this structure automatically:

```json
{
  "config": {
    "analytics": {
      "enabled": false,
      "updated_at": "2026-10-06T00:00:00.000Z"
    }
  }
}
```

For a manual seed, open Realtime Database, select `/config/analytics`, and
import `analytics-config.seed.json`. Import only at that node so other database
content is not replaced.

## Security rules

Mobile apps only need read access to this non-sensitive flag. Admin writes use
the Firebase Admin SDK and bypass client security rules. Merge the
`analytics-config.rules-fragment.json` nodes into the project's existing rules;
do not replace unrelated rules.

## Mobile behavior

Attach one Realtime Database value listener when the app runtime starts. Begin
with collection disabled. On every value change, update the Boolean supplied to
ZoAnalytics and call `collectionStateDidChange()`.

The listener is event driven. It does not poll every second, and Firebase
automatically reconnects it after temporary network loss.
