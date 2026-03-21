# LastBot — Last.fm PHP Stub

A minimal PHP stub that implements the [Last.fm web auth flow](https://www.last.fm/api/webauth) and persists the resulting session key so a bot or script can make authenticated API calls (e.g. scrobbling).

---

## How it works

Last.fm uses a three-step token-based auth flow:

1. **Request token** — the app calls `auth.getToken` to get an unauthorised token.
2. **User authorises** — the user is redirected to Last.fm, which shows a grant-permission page.
3. **Exchange token** — the app calls `auth.getSession` with the now-authorised token to receive a persistent session key.

This stub handles all three steps across two routes:

| Route | Purpose |
|---|---|
| `/lastfm` | Homepage — shows Connect button and config status |
| `/lastfm/callback` | Receives the `token` param if Last.fm redirects back |

If Last.fm shows "Application authenticated" without redirecting (common after the first grant), a **Complete authentication** button appears on the homepage to finish the exchange manually.

---

## Project layout

```
.
├── index.php              # Homepage / connect flow
├── callback/
│   └── index.php          # Token-to-session exchange callback
├── includes/
│   ├── config.php         # Env loader, app_config(), app_session_key()
│   └── lastfm.php         # Last.fm API helpers
├── data/
│   ├── .htaccess          # Blocks all web access to this directory
│   └── session.json       # Written on successful auth (git-ignored)
├── .env.example           # Environment variable template
├── .cpanel.yml            # cPanel Git Deploy manifest (customise before use)
└── README.md
```

---

## Setup

### 1. Register a Last.fm API application

Create an app at https://www.last.fm/api/account/create and note your **API key** and **Shared secret**.

### 2. Configure environment variables

```bash
cp .env.example .env
```

Edit `.env`:

```ini
LASTFM_API_KEY=your_api_key
LASTFM_SHARED_SECRET=your_shared_secret
LASTFM_CALLBACK_URL=https://your-domain.example/lastfm/callback
```

Keep `.env` on the server only — it is in `.gitignore` and must never be committed.

### 3. Deploy

#### cPanel Git Deploy

Edit `.cpanel.yml` and set `DEPLOYPATH` to your web root target, e.g.:

```yaml
- export DEPLOYPATH=/home/YOUR_USERNAME/public_html/lastfm
```

Commit and push. In cPanel → Git Version Control → your repo → Deploy HEAD.

After deploy, create `.env` directly on the server:

```bash
cd /your/deploy/path
cp .env.example .env
chmod 600 .env
nano .env   # fill in real values
```

#### Local (PHP built-in server)

```bash
php -S localhost:8080
```

Open http://localhost:8080/ and set `LASTFM_CALLBACK_URL=http://localhost:8080/callback`.

---

## Auth flow walkthrough

1. Open `/lastfm` and click **Connect Last.fm**.
2. Grant access on the Last.fm page.
3. If redirected back automatically → session key is saved by the callback.
4. If Last.fm shows "Application authenticated" without redirecting → return to `/lastfm` and click **Complete authentication**.
5. The homepage Config Check row **Session key** changes to `saved`.

The session key is written to `data/session.json` (mode `600`, blocked from web access by `.htaccess`). It does not expire unless you revoke the application in your Last.fm settings.

---

## Security notes

- `.env` must be mode `600` and never committed to version control.
- `data/session.json` must be mode `600` and is blocked from HTTP access via `data/.htaccess`.
- The session key must only ever be read server-side.
- The `data/` directory is excluded from git via `.gitignore`.
