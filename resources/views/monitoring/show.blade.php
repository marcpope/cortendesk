@extends('layouts.app')

@section('title', __('monitoring.agent'))
@section('subtitle', __('monitoring.monitoring'))

@section('content')
    <div class="mb-3">
        <a href="{{ route('monitoring') }}" class="fs-13 text-muted"><i class="ri-arrow-left-line me-1"></i>{{ __('monitoring.back_to_monitoring') }}</a>
    </div>
    @livewire(App\Livewire\AgentDetail::class, ['agentId' => $agent], key('agent-detail-'.$agent))
@endsection

@push('scripts')
<script src="{{ \App\Support\Asset::url('assets/vendor/apexcharts/apexcharts.min.js') }}"></script>
<script src="{{ \App\Support\Asset::url('assets/js/monitoring.js') }}"></script>
@endpush
