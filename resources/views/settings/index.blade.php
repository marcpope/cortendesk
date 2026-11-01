@extends('layouts.app')

@section('title', __('common.settings'))
@section('subtitle', __('common.system'))

@section('content')
    @livewire(App\Livewire\SettingsPage::class)
@endsection
