@extends('layouts.app')

@section('title', __('logs.file_transfer_log'))
@section('subtitle', __('common.logs'))

@section('content')
    @livewire(App\Livewire\FileTransferLog::class)
@endsection
