@extends('layouts.app')

@section('title', __('common.strategies'))
@section('subtitle', __('common.manage'))

@section('content')
    @livewire(App\Livewire\StrategyList::class)
@endsection
