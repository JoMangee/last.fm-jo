# LastBot Last.fm PHP Stub

Small PHP stub for Last.fm auth flow with these routes:

- `/lastfm` homepage and connect button
- `/lastfm/callback` callback endpoint that exchanges token for a session

## Setup

1. Copy `.env.example` to `.env`.
2. Fill in:
   - `LASTFM_API_KEY`
   - `LASTFM_SHARED_SECRET`
   - `LASTFM_CALLBACK_URL` (use `https://mesh.net.nz/lastfm/callback`)
3. Deploy to `/home2/meshnet/public_html/lastfm`.
4. Keep your real `.env` on server only and set mode `600`.

## Quick local test

From this folder:

```powershell
php -S localhost:8080
```

Then open:

- http://localhost:8080/

For local testing, set `LASTFM_CALLBACK_URL` to `http://localhost:8080/lastfm/callback`.

## Notes

- This is a starter stub, not production-hard auth storage.
- Keep your shared secret and session key server-side only.
