@extends('layouts.app')

@section('title', 'Client Downloads')
@section('subtitle', 'System')

@section('content')
    @livewire(App\Livewire\ClientDownloadManager::class)
@endsection
