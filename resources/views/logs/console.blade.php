@extends('layouts.app')

@section('title', __('logs.console_audit'))
@section('subtitle', __('common.logs'))

@section('content')
    @livewire(App\Livewire\ConsoleAuditList::class)
@endsection
