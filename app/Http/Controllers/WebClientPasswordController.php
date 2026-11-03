<?php

namespace App\Http\Controllers;

use App\Models\ConsoleAudit;
use App\Services\WebClientAddressBookPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webclient/address-book-password — the web client's sign-in with a
 * password from an address book (see WebClientAddressBookPassword).
 *
 * Called by the session worker the moment the device sends its login
 * challenge, with the device's salt. It sits in the web middleware group, so
 * it needs the console session cookie and the page's CSRF token: a request
 * from another site, or a URL pasted anywhere, gets nothing. Nothing secret
 * travels in the URL, and the answer is marked no-store.
 *
 * Every answer that hands out a value is written to the console audit log —
 * who, which device, which book — and the value itself never is.
 */
class WebClientPasswordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(WebClientAddressBookPassword::enabled(), 404);

        $data = $request->validate([
            'id' => ['required', 'string', 'max:100'],
            // RustDesk salts are short printable strings; bound it anyway.
            'salt' => ['required', 'string', 'max:128', 'regex:/^[\x21-\x7E]+$/'],
        ]);

        $user = $request->user();
        $peerId = (string) preg_replace('/\s+/', '', $data['id']);

        $entry = WebClientAddressBookPassword::entryFor($user, $peerId);
        $h1 = $entry ? WebClientAddressBookPassword::h1Hex($entry, $data['salt']) : null;

        if ($h1 === null) {
            return $this->noStore(response()->json(['error' => 'No address book password for this device'], 404));
        }

        $book = $entry->addressBook;
        ConsoleAudit::record(
            'address-book.password-use',
            'Web client signed in to '.$peerId.' with the password from address book "'.$book->name.'"',
            'device',
            $peerId,
        );

        return $this->noStore(response()->json(['hash' => $h1]));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store, private');
    }
}
