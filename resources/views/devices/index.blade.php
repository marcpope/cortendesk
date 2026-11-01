@extends('layouts.app')

@section('title', __('common.devices'))
@section('subtitle', __('common.manage'))

@section('content')
    @livewire(App\Livewire\DeviceList::class)
@endsection
