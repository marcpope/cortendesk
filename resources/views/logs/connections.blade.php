@extends('layouts.app')

@section('title', __('logs.connection_log'))
@section('subtitle', __('common.logs'))

@section('content')
    @livewire(App\Livewire\ConnectionLog::class)
@endsection
