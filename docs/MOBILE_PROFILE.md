# Mobile profile completion

Android mobile (`ZoStreamNew`) and iOS mobile (`Zo Stream`) check the authenticated
profile when Home opens. An incomplete profile requires a username, gender,
district and village/town for completion. The sheet can be cancelled or swiped
away; an incomplete account is prompted again on the next app entry. Cancellation
does not save completion. No TV or web client UI changed.

The username is a separate handle; existing display names and legacy profile
fields are preserved. Handles are trimmed and stored in lowercase, with 3–30
ASCII letters, digits or underscores. The availability check is debounced by
400 ms on both clients. Submission validates again and a database unique index
prevents two accounts from claiming the same handle concurrently.
Once saved, a handle cannot be changed, including through a direct API request.
Profile Edit displays the handle read-only, alongside gender, district and village.
Its **Edit gender and location** action opens the shared form with a separate Save
changes button; existing name/email/birthday editing retains its own Save action.
Gender options are `male`, `female`, `other`, and `prefer_not_to_say`.

## Deployment

1. Include `Library/mizoram-districts-villages.json` in the backend deployment.
2. Run `php artisan migrate --force` with the normal deployment process. Migration
   `2026_11_01_000001_add_mobile_profile_fields_to_user.php` adds nullable
   `username`, `profile_district_code` and `profile_village_code` columns to `user`.
   Existing accounts start incomplete; they are not assigned generated handles.
   Also apply `2026_11_01_000002_add_gender_to_mobile_profile.php`, which adds
   nullable `profile_gender`. Accounts missing gender are prompted again with
   their existing handle locked.
3. Deploy the backend endpoints before releasing the two updated mobile apps.

This change does not run a migration or deploy/release either app automatically.

## Authenticated API

All routes use `auth.token` and the normal API v4 envelope. The session determines
the account; a client-supplied user ID cannot choose another account.

| Method | Route | Result |
| --- | --- | --- |
| GET | `/api/v4/account/mobile-profile` | `user_id`, `username`, `gender`, district/village codes and names, `complete` |
| GET | `/api/v4/account/mobile-profile/locations` | All 11 districts and 887 LGD village records |
| GET | `/api/v4/account/mobile-profile/username?username=example` | Normalized `username` and `available`; own handle remains available |
| POST | `/api/v4/account/mobile-profile` | Saves `username`, `gender`, `district_code`, `village_code` and returns verified completion |

Username checks are limited to 120/minute and submissions to 20/minute.
Validation failures return 422; a duplicate detected at the unique index returns
409 `USERNAME_TAKEN`. Villages must belong to the selected district in the
directory. Location codes are hidden from existing general user serialization;
they are returned through this authenticated endpoint.

## Client state

SharedPreferences (`mobile_profile_complete_v1_<uid>` in `ZoStreamPrefs`) and
UserDefaults (`mobile.profile.complete.v1.<uid>`) mirror successful server
responses. They never replace a server check. Reinstallation and another device
therefore do not prompt an already complete account, and stale local flags cannot
suppress an incomplete profile. Failed saves do not mark completion.

Both clients ignore stale username checks and responses from a previous account.
Saved usernames appear in smaller text below the name on the main Profile page.
District selection clears village selection. Location pickers support searching;
village rows include their sub-district to distinguish repeated names. Automatic
poll prompts wait while profile completion is pending. Network failures provide
retry actions rather than marking the account complete.

The directory contains every village record in the 1 November 2026 LGD mirror
snapshot. It does not enumerate every neighbourhood, ward or sub-village.

## Verification

- Backend: `php vendor/bin/phpunit tests/Feature/MobileProfileTest.php`
- Android: `./gradlew :app:testDebugUnitTest --tests '*MobileProfileGateTest' --offline`
- iOS coordinator checks (macOS without an iOS SDK): from the iOS project root,
  `swiftc -parse-as-library -o /tmp/zostream-mobile-profile-checks 'Zo Stream/Api/MobileProfileModels.swift' 'Zo Stream/View Model/MobileProfileCoordinator.swift' Tests/MobileProfileCoordinatorChecks.swift`,
  then run `/tmp/zostream-mobile-profile-checks`.

The iOS checks compile the production models/coordinator with a deterministic API
double. An iOS SDK/Xcode build and physical-device UI validation remain required
before app release.
