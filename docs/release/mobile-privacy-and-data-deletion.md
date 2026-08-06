# LinguaCafe mobile privacy and data deletion

Version: 2026-08-01 release candidate

This text is the source for the public privacy/support pages of a LinguaCafe
mobile release. The deployment owner must publish it at stable HTTPS URLs and
replace deployment-specific contact details before App Store submission.

## Data handled

LinguaCafe is a client for a user-selected LinguaCafe server. It does not use an
advertising SDK and does not track users across apps or websites.

The selected server receives and stores data needed to provide the learning
service:

- account name and email address;
- a random app installation identifier and device/app version;
- imported English learning material and user-created meanings;
- review ratings, timing, scheduling state, markers, tags and progress;
- queued offline actions when connectivity returns;
- user-selected audio/media and ordinary server security/diagnostic logs.

These data are linked to the signed-in account because that is required to keep
each user's library, review history and schedule isolated. LinguaCafe does not
sell the data or use it for third-party advertising.

## On-device data

The app stores its server address, random device id, validated account/language
scope, downloaded short-term article/review packages, queued actions, and cached
audio. The native session token is protected by Android Keystore or Apple
Keychain; the password is not stored. Notifications are optional and scheduled
locally. A document is accessed only after the user selects it in the system
file picker.

“撤销此设备并退出” revokes the device when the server is reachable, removes the
native token and cached account scope, deletes that scope's offline package and
queue, and clears the shared mobile media cache. A server outage cannot prevent
the local deletion.

## Server data deletion

The mobile app does not silently delete the server account when signing out.
The iOS client does not offer account creation. A user requests server-data
deletion from the administrator of the server address shown in Settings. The
administrator must verify the account,
explain any legally required retention, delete or anonymize the account data,
and confirm completion.

This administrator-request path is not a self-service deletion claim. If a
future mobile release adds account creation, it must also add an easy-to-find
in-app initiation path that deletes the entire account and associated data.

## Permissions

- Network: connect to the user-selected LinguaCafe server.
- Notifications: optional daily review reminder.
- Files: user-initiated access to the selected `.txt` document only; no broad
  file-system access.
- Haptics/audio: rating feedback and pronunciation playback.

No camera, microphone, contacts, location, advertising identifier or photo
library permission is requested by the M9 client.
