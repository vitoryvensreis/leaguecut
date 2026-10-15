# Instagram long-lived token refresh — staging only

The Instagram Login token refresh flow requests `GET https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token&access_token=...`.

`lc_instagram_refresh_due` returns true only for unrevoked tokens at least 24 hours old, still valid and within 30 days of expiry. `lc_social_oauth_refresh_instagram` parses and validates the new token and its lifetime, encrypts it, and updates using a compare-and-swap guard to prevent stale refreshes reviving disconnected sessions. It **does not schedule jobs**.

Important: `connected_at` is the original connection time; refresh is only called within the last 30 days of an existing valid token. An operator should refresh through a manually authorized operation or add a separately reviewed scheduler after real API tests.

Never print request URLs: they contain tokens. Do not log HTTP response bodies. Meta API permission `instagram_business_basic` must remain granted. Rate limits, owner consent, remote expiry, user disconnect and app-review requirements remain release blockers.

Tests: `php tests/instagram-refresh.php`, syntax checks and staging OAuth end-to-end with approved Meta application.
