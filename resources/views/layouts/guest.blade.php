<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8"/>
    <title>@yield('title', 'Sign In') | {{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ \App\Support\Asset::url('assets/images/cortendesk-sm.svg') }}">

    <script src="{{ \App\Support\Asset::url('assets/js/theme-init.js') }}"></script>
    <link href="{{ \App\Support\Asset::url('assets/vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/vendor/remixicon/remixicon.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/fonts/figtree/figtree.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/css/spacing.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/css/shell.css') }}" rel="stylesheet" type="text/css"/>
    <link href="{{ \App\Support\Asset::url('assets/css/cortendesk.css') }}" rel="stylesheet" type="text/css"/>
</head>

<body class="rd-auth-bg position-relative">

    <div class="rd-auth-page pt-2 pt-sm-5 pb-4 pb-sm-5 position-relative">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-xxl-4 col-lg-5">

                    @yield('content')

                    <div class="text-center mt-3">
                        <p class="rd-auth-note">CortenDesk — self-hosted RustDesk console</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="{{ \App\Support\Asset::url('assets/vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ \App\Support\Asset::url('assets/js/shell.js') }}"></script>
</body>
</html>
