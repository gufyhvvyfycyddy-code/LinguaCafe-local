# LinguaCafe mobile privacy and data deletion

Version: 2026-08-31 public Android/iOS mobile privacy policy

This policy describes the current LinguaCafe Android and iOS mobile clients.
The mobile app also carries a concise in-app copy so privacy information remains
available before and after sign-in. Privacy requests about data held by a selected
LinguaCafe server should be directed to that server operator. Privacy requests
about the distributed mobile app should use the publisher contact mechanism on
the store Support URL. The publisher must keep the public policy, store privacy
disclosures, and actual release-server data practices aligned.

## Data handled

LinguaCafe is a client for a user-selected LinguaCafe server. It does not use an
advertising SDK and does not track users across apps or websites.

The selected server stores the existing account profile and receives data needed
to provide the learning service. The current mobile client sends the account email
at sign-in but does not upload the account name or numeric/UUID user id as separate
profile fields. It also sends or persists through the selected server:

- a random app installation identifier, device name/platform and app version;
- imported English learning material and user-created meanings;
- review ratings, timing, scheduling state, markers, tags and reading progress;
- queued offline actions when connectivity returns;
- user-selected audio/media references needed to deliver the learning content.

The standard public deployment also uses Apache combined access logs. A dictionary
lookup places the searched term in the request URL, so the access log can retain that
search term together with ordinary request and client-IP diagnostic metadata after the
request completes. A custom server operator can choose a different retention policy.
Account/device/learning records are linked to the signed-in account; access-log search
and diagnostic data are conservatively disclosed as linked because the selected server
can correlate authenticated service activity. LinguaCafe does not sell these data, use
them for third-party advertising, or use them to track users across apps or websites.

The mobile client does not directly integrate an advertising network, analytics SDK,
or social-login provider. A LinguaCafe server operator can separately configure
external AI, translation, dictionary, or other processing services. When such a
processor is enabled, that operator controls which server-side data is sent to it and
must disclose the processor and purpose in the operator's public privacy information
and require protection consistent with this policy and applicable law. The mobile
client's learning/API requests continue to go to the user-selected LinguaCafe server.

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

The mobile app does not silently delete the server account when signing out or
revoking a device. Server-account deletion is a separate destructive action.
A signed-in user can open the LinguaCafe Web account settings on the selected
server and use the permanent account-deletion control. It requires the exact
confirmation text `delete my account` and the current account password.

Successful Web account deletion removes the current active account row, active
learning/application rows owned by that user, Sanctum/mobile access tokens,
registered mobile devices, password-reset state and the user's uploaded media
from its active account path. It also invalidates the current Web session. The
action is scoped to the signed-in account and does not delete another user's
rows. The final administrator account cannot self-delete because the server
must retain an administrator identity. Media is moved out of the active account
path before database deletion so a failed database transaction can restore it;
a rare final storage-purge failure leaves only private quarantine residue and is
reported for operator cleanup rather than making the deleted account accessible
again.

Operational database backups are recovery artifacts and are not rewritten by
the self-service account-deletion request. Data present in an older backup can
therefore remain until that server's backup-retention policy removes the backup.
A deployment operator remains responsible for the published retention policy,
legal retention requirements, and any exceptional deletion request concerning
retained recovery backups.

The current Android and iOS clients sign in to an existing LinguaCafe server
account and do not offer or link to account creation. If a future mobile release
adds or links to mobile account creation, it must also add an easy-to-find
in-app initiation path for the same server-account deletion capability rather
than treating device revocation as account deletion.

## Permissions

- Network: connect to the user-selected LinguaCafe server.
- Notifications: optional daily review reminder.
- Files: user-initiated access to the selected `.txt` document only; no broad
  file-system access.
- Haptics/audio: rating feedback and pronunciation playback.

No camera, microphone, contacts, location, advertising identifier or photo
library permission is requested by the current mobile client.
