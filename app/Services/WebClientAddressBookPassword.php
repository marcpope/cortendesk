<?php

namespace App\Services;

use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\AddressBookRule;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Lets the web client sign in with a password kept in an address book, the
 * way the RustDesk desktop client does for a book it can read.
 *
 * The browser is never handed the stored password. It sends the salt the
 * device announced in its login challenge, and gets back h1 =
 * SHA256(password || salt): the value the device itself checks, and what the
 * web client already keeps for "Save password". h1 is bound to that device's
 * salt, so it is useless against any other device sharing the password.
 *
 * Who may use an entry is exactly who may read it through the client API
 * (/api/ab/peers): read access to a shared book, or the owner of a personal
 * book. That route already returns the plaintext to a desktop client, so this
 * hands out nothing a user could not already obtain.
 */
class WebClientAddressBookPassword
{
    public static function enabled(): bool
    {
        return (bool) config('cortendesk.native_webclient')
            && (bool) config('cortendesk.webclient_address_book_passwords');
    }

    /**
     * The entry whose password $user may use for $peerId, or null. A shared
     * book's escrowed password wins over the user's own personal-book hash:
     * it is the one an administrator keeps current. Among several shared
     * books, the oldest entry wins, so the choice is stable. Entries with
     * nothing usable (blank or undecryptable password, malformed hash) are
     * skipped, so a non-null result always yields an h1.
     */
    public static function entryFor(User $user, string $peerId): ?AddressBookEntry
    {
        if ($peerId === '') {
            return null;
        }

        $groupIds = null;
        $personal = null;

        $entries = AddressBookEntry::query()
            ->where('rustdesk_id', $peerId)
            ->where(fn ($q) => $q->whereNotNull('password_enc')
                ->orWhere(fn ($q) => $q->whereNotNull('hash')->where('hash', '!=', '')))
            ->with('addressBook.rules')
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $book = $entry->addressBook;
            if (! $book instanceof AddressBook) {
                continue;
            }

            if ($book->is_personal) {
                // Only the owner's own book: a personal hash is that person's
                // remembered credential, not something to share (admins
                // included, even for an orphaned book).
                if ($personal === null && (int) $book->owner_user_id === (int) $user->id
                    && self::personalHashBytes($entry) !== null) {
                    $personal = $entry;
                }

                continue;
            }

            if ($entry->password_enc === null) {
                continue;
            }

            $groupIds ??= $user->is_admin
                ? []
                : array_map('intval', $user->groups()->pluck('user_groups.id')->all());

            if ($book->permissionFor($user, $groupIds) >= AddressBookRule::PERM_READ
                && self::sharedPassword($entry) !== null) {
                return $entry;
            }
        }

        return $personal;
    }

    /**
     * h1 as lowercase hex for this entry and the device's salt, or null when
     * the entry holds nothing usable (blank or undecryptable password, or a
     * personal hash that is not a 32-byte SHA-256).
     */
    public static function h1Hex(AddressBookEntry $entry, string $salt): ?string
    {
        if ($entry->addressBook?->is_personal) {
            // The desktop client stores h1 itself, base64-encoded: the salt is
            // the device's own and was already applied when it was saved.
            $bytes = self::personalHashBytes($entry);

            return $bytes === null ? null : bin2hex($bytes);
        }

        $password = self::sharedPassword($entry);

        return $password === null ? null : hash('sha256', $password.$salt);
    }

    /** The escrowed plaintext, or null when blank or undecryptable (rotated APP_KEY). */
    private static function sharedPassword(AddressBookEntry $entry): ?string
    {
        if ($entry->password_enc === null) {
            return null;
        }

        try {
            $password = Crypt::decryptString((string) $entry->password_enc);
        } catch (DecryptException) {
            return null;
        }

        return $password === '' ? null : $password;
    }

    private static function personalHashBytes(AddressBookEntry $entry): ?string
    {
        $bytes = base64_decode((string) $entry->hash, true);

        return is_string($bytes) && strlen($bytes) === 32 ? $bytes : null;
    }
}
