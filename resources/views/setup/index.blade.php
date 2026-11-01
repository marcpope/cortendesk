@extends('layouts.app')

@section('title', __('setup.first_run_setup'))

@section('content')
    @livewire(App\Livewire\SetupWizard::class)
@endsection