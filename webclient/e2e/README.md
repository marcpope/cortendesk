# Web client e2e tests (Playwright)

Optional browser tests of the **built** web client (`public/rdclient`), on phone, tablet and desktop
browsers. No Laravel and no RustDesk: `server.mjs` serves the bundle with a static copy of the
web client page (`fixture/index.html`, mirrors `resources/views/webclient.blade.php`) and a mock
session worker (`fixture/mock-worker.js`) that "connects" at once to a 1920x1080 Windows host. The
page records every command it sends to the worker, and the specs assert on those commands
(mouse masks and coordinates, keys, text) and on the layout.

## Run

```sh
cd webclient/e2e
npm ci
npx playwright install chromium webkit   # once
npx playwright test                      # all device projects
npx playwright test --project=pixel      # one project
npx playwright show-report               # after a failure (traces are kept)
```

Rebuild the bundle first (`cd webclient && npm run build`) when testing source changes.

## Projects

| Project | Device | Engine | What |
| --- | --- | --- | --- |
| `iphone` | iPhone 14 | WebKit | phone specs |
| `pixel` | Pixel 7 | Chromium | phone specs |
| `ipad` | iPad Pro 11 | WebKit | phone/tablet specs |
| `desktop` | Desktop Chrome | Chromium | desktop regression: layout, mouse sequence, Map keys, Type |
| `smoke` | Pixel 7 | Chrome/Edge | optional real-host test, see below |

The phone specs (`tests/mobile.spec.ts`) cover the touch and on-screen keyboard UI that ships in the
release after 1.20.0. Against a bundle without it they skip instead of failing. Multi-touch is
synthetic `PointerEvent`s (the same in every engine). Real taps use `page.touchscreen`. Where an
engine has no WebCodecs or OffscreenCanvas (Playwright's WebKit on some platforms), the fixture
stands in for them: the input layer under test does not use them.

Hooks for specs: the fixture keeps the last worker in `window.__worker`, counts connects in
`window.__connects` and records commands in `window.__cmds`. The mock worker answers
`{c:'__drop'}` (connection dies, as on a Windows sign-in) and `{c:'__peerclose'}` (the host closes
with a close_reason).

## Optional: real-host smoke test

`tests/smoke.spec.ts` signs in to a real console, opens the web client for one device, waits for
video, taps the middle of the screen and saves screenshots. It types nothing on the device. It is
skipped unless all of these are set, and must never be set in public CI:

| Variable | Value |
| --- | --- |
| `CD_E2E_URL` | console URL, e.g. `https://console.example.com` |
| `CD_E2E_USER` | a console user with password sign-in |
| `CD_E2E_PASSWORD_FILE` | path of a file holding that user's password (not the password itself) |
| `CD_E2E_PEER` | device ID |
| `CD_E2E_PEER_PASSWORD_FILE` | optional: file with the device password, if the console has none for it |
| `CD_E2E_CHANNEL` | optional: `chrome` (default) or `msedge` (real video needs H.264) |

```sh
CD_E2E_URL=https://console.example.com CD_E2E_USER=admin CD_E2E_PASSWORD_FILE=~/.cd-pw \
  CD_E2E_PEER=123456789 npx playwright test --project=smoke
```

## CI

`github-workflow.example.yml` is a ready workflow (copy it to `.github/workflows/`). It runs the
four mock projects only.
