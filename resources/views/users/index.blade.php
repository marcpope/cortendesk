@extends('layouts.app')

@section('title', __('common.users'))
@section('subtitle', __('common.manage'))

@section('content')
    @livewire(App\Livewire\UserList::class)

    {{-- Pending invitations (PLAN D1) — stable key keeps it mounted across saves.
         Nested, so a 403 from it would take the whole Users page down: a role with
         view-only access to users simply does not render it (PLAN D4). --}}
    @if (auth()->user()?->consoleAllows('user', 'rw'))
        @livewire(App\Livewire\InvitationManager::class, [], 'invitations')
    @endif
@endsection
