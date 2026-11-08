@extends('layouts.app')

@section('title', __('monitoring.monitoring'))
@section('subtitle', __('nav.monitor'))

@section('content')
    @livewire(App\Livewire\MonitoringDashboard::class)
@endsection

@push('scripts')
<script src="{{ \App\Support\Asset::url('assets/vendor/apexcharts/apexcharts.min.js') }}"></script>
<script src="{{ \App\Support\Asset::url('assets/js/monitoring-fleet.js') }}"></script>
@endpush
