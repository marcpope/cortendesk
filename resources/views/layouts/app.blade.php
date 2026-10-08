<!DOCTYPE html>
<html lang="en" data-bs-theme="dark" data-rd-sidebar="expanded">
<head>
    <meta charset="utf-8"/>
    <title>@yield('title', 'Console') | {{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="CortenDesk — self-hosted RustDesk server console"/>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ \App\Support\Asset::url('assets/images/cortendesk-sm.svg') }}">

    <!-- Theme + sidebar state before first paint (no flash) -->
    <script src="{{ \App\Support\Asset::url('assets/js/theme-init.js') }}"></script>

    <link href="{{ \App\Support\Asset::url('assets/vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/vendor/remixicon/remixicon.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/fonts/figtree/figtree.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/css/spacing.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/css/shell.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/css/cortendesk.css') }}" rel="stylesheet" type="text/css"/>
</head>

<body>
    <div class="rd-app">

        @include('layouts.partials.topbar')

        @include('layouts.partials.sidebar')

        <div class="rd-main">
            <div class="rd-content">
                <div class="container-fluid">

                    <div class="row">
                        <div class="col-12">
                            <div class="rd-page-head">
                                <div class="rd-page-crumbs">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="{{ route('overview') }}">CortenDesk</a></li>
                                        @hasSection('subtitle')
                                            <li class="breadcrumb-item"><a href="javascript:void(0);">@yield('subtitle')</a></li>
                                        @endif
                                        <li class="breadcrumb-item active">@yield('title')</li>
                                    </ol>
                                </div>
                                <h4 class="rd-page-title">@yield('title')</h4>
                            </div>
                        </div>
                    </div>

                    @yield('content')

                </div>
            </div>

            <footer class="rd-footer">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-md-6">
                            <script>document.write(new Date().getFullYear())</script> © CortenDesk
                        </div>
                        <div class="col-md-6">
                            <div class="text-md-end d-none d-md-block">
                                <span class="text-muted">Self-hosted RustDesk console</span>
                            </div>
                        </div>
                    </div>
                </div>
            </footer>
        </div>

    </div>

    <script src="{{ \App\Support\Asset::url('assets/vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ \App\Support\Asset::url('assets/js/shell.js') }}"></script>
    <script src="{{ \App\Support\Asset::url('assets/js/cortendesk.js') }}"></script>

    @stack('scripts')
</body>
</html>
