<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Bootstrap endpoints for the self-hosted V1 web client (AGPL flutter build,
 * served as static files by nginx at /webclient/ — not part of this app).
 *
 * Contract mirrors lejianwen/rustdesk-api (MIT, referenced for protocol
 * behavior only): the web client loads /webclient-config/index.js before
 * login, then POSTs /api/server-config with its bearer token to obtain the
 * ID server, public key, and the user's personal address book as peer cards.
 */
class WebClientController extends Controller
{
    /**
     * GET /webclient-config/index.js — pre-login bootstrap script.
     * Points the web client's API calls back at this console.
     */
    public function configJs(Request $request): Response
    {
        $apiServer = $request->getSchemeAndHttpHost();

        $js = "localStorage.setItem('api-server', '{$apiServer}');\n"
            . "const ws2_prefix = 'wc-';\n"
            . "localStorage.setItem(ws2_prefix + 'api-server', '{$apiServer}');\n"
            . "window.webclient_magic_queryonline = 0;\n";

        return response($js, 200, ['Content-Type' => 'application/javascript']);
    }

    /**
     * POST /api/server-config — {code:0, message:"success", data:{id_server,
     * key, peers}} where peers maps rustdesk_id => peer card built from the
     * caller's personal address book (first 100 entries).
     */
    public function serverConfig(Request $request): JsonResponse
    {
        $user = $request->user();
        $book = AddressBook::personalFor($user);

        $peers = [];
        foreach ($book->entries()->limit(100)->get() as $entry) {
            $peers[$entry->rustdesk_id] = $this->peerCard($entry);
        }

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'id_server' => Setting::server('id_server'),
                'key' => Setting::server('public_key'),
                'peers' => (object) $peers,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function peerCard(AddressBookEntry $entry): array
    {
        return [
            'view-style' => 'shrink',
            'tm' => (int) (now()->subDay()->getPreciseTimestamp(0)) * 1_000_000_000,
            'info' => [
                'username' => (string) $entry->username,
                'hostname' => (string) $entry->hostname,
                'platform' => (string) $entry->platform,
                'hash' => (string) $entry->hash,
                'id' => (string) $entry->rustdesk_id,
            ],
            'tmppwd' => '',
        ];
    }
}
