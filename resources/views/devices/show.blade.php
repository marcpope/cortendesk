@extends('layouts.app')

@section('title', __('common.device'))
@section('subtitle', __('common.manage'))

@section('content')
    <div class="mb-3">
        <a href="{{ route('devices') }}" class="fs-13 text-muted"><i class="ri-arrow-left-line me-1"></i>{{ __('devices.back_to_devices') }}</a>
    </div>
    @livewire(App\Livewire\DeviceDetail::class, ['deviceId' => $device], key('device-detail-'.$device))
@endsection
