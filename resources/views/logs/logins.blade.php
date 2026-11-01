@extends('layouts.app')

@section('title', __('logs.login_log'))
@section('subtitle', __('common.logs'))

@section('content')
    @livewire(App\Livewire\LoginLogList::class)
@endsection
