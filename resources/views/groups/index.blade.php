@extends('layouts.app')

@section('title', __('common.groups'))
@section('subtitle', __('common.manage'))

@section('content')
    @livewire(App\Livewire\GroupList::class)
@endsection
