@extends('layouts.app')

@section('title', __('common.client_downloads'))
@section('subtitle', __('common.system'))

@section('content')
    @livewire(App\Livewire\ClientDownloadManager::class)
@endsection
