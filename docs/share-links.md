# Share links

A share link lets someone with nothing but a browser connect to one device
through the built-in web client. They open `https://<console>/s#<token>`;
there is no account and no RustDesk install. The typical case is showing your
own screen to people whose network or meeting software makes screen sharing
painful: the web client only needs the console's HTTPS port.

## Creating a link

On a device's page (or with **Share screen** in the device list) choose:

- **For whom**: a label, shown in the list, the log and the device's accept
  window.
- **Valid for**: 15 minutes, 1 hour (default), 8 hours, 24 hours or 7 days.
- **Viewers at the same time**: 1 to 10 (default 1).

The link is shown once. Only its SHA-256 is stored; a lost link means a new
link. Creating and ending a link need write access to the device, and both go
to the console audit log, as does every time a link is opened.

## Who decides what

The viewer signs in **without a password**. The device then opens RustDesk's
own accept window, showing the link's label, and the person at the device
accepts or refuses, and can turn off keyboard, clipboard, file transfer and
the rest there, as for any incoming connection. The device must therefore
allow accepting by click (*Security → Accept sessions via click*, or *via
both*); on a password-only device the viewer just waits.

So a share link is for **attended** devices. The console handles only:

- **The token**: in the URL fragment, so it never reaches server or proxy
  logs; the page posts it to `/s/start`.
- **Expiry and ending**: an expired or ended link no longer starts sessions.
  An open share page pings `/s/ping` every 30 seconds and disconnects when
  the link has ended or expired, so **End** takes up to half a minute.
- **The viewer limit**: `/s/start` claims a slot, the ping keeps it, and
  closing the page (or 90 seconds without a ping) frees it.
- **The log**: each viewer is recorded with IP, user agent, start, last ping
  and end.

What the console cannot do: a viewer's browser that ignores the end signal
keeps its RustDesk session until the person at the device disconnects it,
which they can do from the connection window at any time. The viewer also
learns the device's RustDesk ID, as with any web client session; connecting
to it outside the link still needs the device's password or someone to
accept.

## Settings

| Variable | Default | |
|---|---|---|
| `CORTENDESK_SHARE_LINKS` | `true` | `false` hides the card and turns off `/s`. |
