@extends('layouts.app')

@section('title', __('common.roles'))
@section('subtitle', __('common.manage'))

@section('content')
    @livewire(App\Livewire\RoleList::class)
@endsection
