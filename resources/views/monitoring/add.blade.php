@extends('layouts.app')

@section('title', __('monitoring.add_agent'))
@section('subtitle', __('monitoring.monitoring'))

@section('content')
    <a href="{{ route('monitoring') }}" class="d-inline-flex align-items-center gap-1 fs-13 text-muted mb-3"><i class="ri-arrow-left-line"></i>{{ __('monitoring.back_to_monitoring') }}</a>
    @livewire(App\Livewire\AgentInstallGuide::class)
@endsection
