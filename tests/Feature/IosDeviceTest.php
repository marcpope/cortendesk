<?php

use App\Livewire\AddressBookManager;
use App\Livewire\DeviceList;
use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\Device;
use App\Models\User;
use App\Support\ClientLink;
use Livewire\Livewire;

/*
 * RustDesk on iOS only starts sessions, so the console offers no way to
 * connect to an iOS device (issue #133).
 */

function iosDevice(string $id, string $os): Device
{
    return Device::create(['rustdesk_id' => $id, 'uuid' => 'u-'.$id, 'hostname' => 'host-'.$id, 'os' => $os]);
}

beforeEach(function () {
    config(['cortendesk.native_webclient' => true, 'cortendesk.webclient_url' => 'https://web.example.com/']);
    $this->admin = User::factory()->admin()->create();
});

it('knows which platforms can be controlled', function () {
    expect(iosDevice('100000001', 'ios / iOS 18.1')->canBeControlled())->toBeFalse()
        ->and(iosDevice('100000002', 'android / Android 14')->canBeControlled())->toBeTrue()
        ->and(iosDevice('100000003', 'macos / macOS 15.1')->canBeControlled())->toBeTrue()
        ->and(iosDevice('100000004', 'windows / Windows 11 Pro')->canBeControlled())->toBeTrue();
});

it('offers no connect actions for an iOS device in the list', function () {
    iosDevice('111111111', 'ios / iOS 18.1');
    iosDevice('222222222', 'windows / Windows 11 Pro');

    Livewire::actingAs($this->admin)->test(DeviceList::class)
        ->assertDontSeeHtml(ClientLink::to('111111111'))
        ->assertDontSeeHtml('?id=111111111')
        ->assertSee(__('devices.outgoing_only'))
        ->assertSeeHtml(ClientLink::to('222222222'))
        ->assertSeeHtml(route('webclient').'?id=222222222')
        ->assertSeeHtml('https://web.example.com/?id=222222222');
});

it('shows an iOS device as outgoing only on its page, without connect buttons', function () {
    $device = iosDevice('111111111', 'ios / iOS 18.1');

    $this->actingAs($this->admin)->get('/devices/'.$device->id)
        ->assertOk()
        ->assertSee(__('devices.outgoing_only'))
        ->assertDontSee(ClientLink::to('111111111'), false)
        ->assertDontSee('?id=111111111', false);
});

it('does not link an iOS address book entry to the client', function () {
    $book = AddressBook::create(['name' => 'Shared', 'owner_user_id' => $this->admin->id, 'is_personal' => false]);
    AddressBookEntry::create(['address_book_id' => $book->id, 'rustdesk_id' => '111111111', 'platform' => 'iOS']);
    AddressBookEntry::create(['address_book_id' => $book->id, 'rustdesk_id' => '222222222', 'platform' => 'Windows']);

    Livewire::actingAs($this->admin)->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->assertDontSeeHtml(ClientLink::to('111111111'))
        ->assertSeeHtml(ClientLink::to('222222222'));
});
