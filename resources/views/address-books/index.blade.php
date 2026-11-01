@extends('layouts.app')

@section('title', __('common.address_books'))
@section('subtitle', __('common.manage'))

@section('content')
    @livewire(App\Livewire\AddressBookManager::class)
@endsection
