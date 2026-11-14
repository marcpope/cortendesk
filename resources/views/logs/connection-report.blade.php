@extends('layouts.app')
@section('title', __('report.title'))
@section('subtitle', __('common.logs'))
@section('content')
    @livewire(App\Livewire\ConnectionReport::class)
@endsection
