@extends('layouts.app')

@section('title', __('logs.alarm_log'))
@section('subtitle', __('common.logs'))

@section('content')
    @livewire(App\Livewire\AlarmLogList::class)
@endsection
