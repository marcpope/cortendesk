<?php

namespace App\Services;

use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\AddressBookRule;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A device's place in address books (issues #54, #85).
 *
 * Entries are per-book contact rows keyed by RustDesk id, not a foreign key to
 * devices, so deleting a device leaves them behind unless removed here. Write
 * access is AddressBook::permissionFor, the same check the address-book screen
 * and the client AB API use.
 */
class DeviceAddressBooks
{
    /**
     * Books $user may add entries to: their personal book plus any shared book
     * where their tier is read-write or better. Empty without the address-book
     * "Manage" permission.
     *
     * @return Collection<int, AddressBook>
     */
    public static function writableBooks(User $user): Collection
    {
        if (! $user->consoleAllows('address_book', 'rw')) {
            return collect();
        }

        $groupIds = $user->is_admin ? [] : $user->userGroupIds();

        return AddressBook::query()
            ->with('rules')
            ->orderByDesc('is_personal')
            ->orderBy('name')
            ->get()
            ->filter(fn (AddressBook $b) => $b->permissionFor($user, $groupIds) >= AddressBookRule::PERM_READ_WRITE)
            ->values();
    }

    /** May $user add or remove entries in $book? */
    public static function canWrite(User $user, AddressBook $book, ?array $groupIds = null): bool
    {
        return $user->consoleAllows('address_book', 'rw')
            && $book->permissionFor($user, $groupIds) >= AddressBookRule::PERM_READ_WRITE;
    }

    /**
     * The entry fields a device carries into a book. platform is the
     * RustDesk-style name ("Windows", "Mac OS") clients match icons against,
     * not the console's lowercase slug.
     *
     * @return array<string, mixed>
     */
    public static function entryAttributes(Device $device): array
    {
        return [
            'rustdesk_id' => $device->rustdesk_id,
            'alias' => $device->alias ?: null,
            'hostname' => $device->hostname ?: null,
            'platform' => $device->rustdeskPlatform(),
            'username' => $device->username ?: null,
            'tag_ids' => [],
        ];
    }

    /**
     * Entries across all books for these RustDesk ids, whoever can see them.
     *
     * @param  array<int, string>  $rustdeskIds
     */
    public static function entryCount(array $rustdeskIds): int
    {
        if ($rustdeskIds === []) {
            return 0;
        }

        return AddressBookEntry::whereIn('rustdesk_id', $rustdeskIds)->count();
    }

    /**
     * Remove entries for these RustDesk ids. With an actor, only books that
     * actor may write are touched and the rest are counted as kept. Without
     * one (admin API, where the token's permission is the whole check) every
     * book is touched. One audit row per book changed.
     *
     * @param  array<int, string>  $rustdeskIds
     * @return array{removed: int, kept: int, books: int}
     */
    public static function remove(array $rustdeskIds, ?User $actor, string $source = ''): array
    {
        $result = ['removed' => 0, 'kept' => 0, 'books' => 0];
        if ($rustdeskIds === []) {
            return $result;
        }

        $groupIds = $actor && ! $actor->is_admin ? $actor->userGroupIds() : [];

        $byBook = AddressBookEntry::whereIn('rustdesk_id', $rustdeskIds)->get()->groupBy('address_book_id');
        $books = AddressBook::with('rules')->whereIn('id', $byBook->keys())->get()->keyBy('id');

        foreach ($byBook as $bookId => $entries) {
            $book = $books->get($bookId);

            if ($book === null || ($actor !== null && ! self::canWrite($actor, $book, $groupIds))) {
                $result['kept'] += $entries->count();

                continue;
            }

            AddressBookEntry::whereIn('id', $entries->pluck('id'))->delete();
            $count = $entries->count();
            $result['removed'] += $count;
            $result['books']++;

            ConsoleAudit::record(
                'address-book.peer-delete',
                'Removed '.$count.' '.Str::plural('entry', $count).' ('.$entries->pluck('rustdesk_id')->unique()->join(', ')
                    .') from address book '.$book->name.' after device delete'.$source,
                'address-book',
                $book->name,
            );
        }

        return $result;
    }

    /** "Removed 3 address-book entries; 1 kept in books you cannot edit." */
    public static function summary(array $result): string
    {
        $text = 'Removed '.$result['removed'].' address-book '.Str::plural('entry', $result['removed']).'.';

        if ($result['kept'] > 0) {
            $text .= ' '.$result['kept'].' kept in books you cannot edit.';
        }

        return $text;
    }
}
