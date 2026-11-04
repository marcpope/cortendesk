<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\ServerLink;
use App\Services\WebClientAddressBookPassword;
use App\Support\IceServers;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebClientPageController extends Controller
{
    /**
     * Full-screen native web client page. WebSocket endpoints come from
     * explicit config when set, otherwise from the address this console is
     * served on — /ws/id and /ws/relay are bridged on that same origin.
     */
    public function show(Request $request): View
    {
        $wsIdUrl = config('cortendesk.ws_id_url');
        $wsRelayUrl = config('cortendesk.ws_relay_url');

        if (! $wsIdUrl || ! $wsRelayUrl) {
            $base = $this->wsBase($request);
            $wsIdUrl = $wsIdUrl ?: ($base ? "{$base}/ws/id" : '');
            $wsRelayUrl = $wsRelayUrl ?: ($base ? "{$base}/ws/relay" : '');
        }

        $user = $request->user();

        // The web client's own strings: the active language over English, so a
        // key missing from a translation still shows readable text.
        $messages = array_replace(
            (array) trans('webclient', [], 'en'),
            (array) trans('webclient'),
        );

        $peerId = (string) $request->query('id', '');

        // Address-book sign-in (WebClientAddressBookPassword): the page learns
        // only where to ask and whether this device has a usable entry, so it
        // can connect without a prompt. The password stays on the server.
        $addressBook = WebClientAddressBookPassword::enabled() ? [
            'url' => route('webclient.address-book-password', [], false),
            'csrfToken' => csrf_token(),
            'available' => WebClientAddressBookPassword::entryFor($user, (string) preg_replace('/\s+/', '', $peerId)) !== null,
        ] : null;

        return view('webclient', [
            'messages' => $messages,
            'peerId' => $peerId,
            'serverKeyB64' => Setting::server('public_key'),
            'wsIdUrl' => $wsIdUrl,
            'wsRelayUrl' => $wsRelayUrl,
            'myId' => 'web-'.$user->id,
            'myName' => $user->name ?: ($user->username ?? 'CortenDesk'),
            // Lets the ID server tell this page from an unknown device when
            // only approved devices may connect (docs/server-link.md).
            'signalToken' => ServerLink::webTicket($user),
            // RustDesk 1.5 WebRTC: null when the console has it off.
            'webrtc' => IceServers::forWebClient(),
            'addressBook' => $addressBook,
        ]);
    }

    /**
     * WebSocket origin for the bridge, e.g. "ws://10.0.0.5:8080".
     *
     * Taken from APP_URL, which is what the deployment declares as its public
     * address and what drives the ID server and relay too; the current request
     * covers a missing or unparseable one. Both the scheme and the port carry
     * over: this used to be hardcoded to wss on an implicit 443, which broke
     * every install not behind TLS on the default port (#23).
     */
    private function wsBase(Request $request): string
    {
        $parts = parse_url(trim((string) config('app.url')));

        if (! is_array($parts) || empty($parts['host'])) {
            $parts = parse_url($request->getSchemeAndHttpHost());
        }

        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $secure = ($parts['scheme'] ?? 'http') === 'https';
        $scheme = $secure ? 'wss' : 'ws';
        $port = $parts['port'] ?? null;

        // A default port is implied by the scheme; anything else has to be
        // spelled out or the browser dials 80/443.
        if ($port === ($secure ? 443 : 80)) {
            $port = null;
        }

        return $scheme.'://'.$parts['host'].($port ? ':'.$port : '');
    }
}
