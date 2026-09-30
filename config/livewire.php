<?php

/*
|--------------------------------------------------------------------------
| Livewire — temporary file uploads only
|--------------------------------------------------------------------------
|
| Deliberately a partial config: Livewire merges this over its own defaults
| (mergeConfigFrom), so every key it does not mention keeps whatever the
| installed version ships. Publishing the whole file would freeze defaults we
| have no opinion about and would have to be re-reconciled on every upgrade.
|
| The one thing we do have an opinion about is the upload ceiling. Livewire
| validates the temporary upload itself, BEFORE a component's own rules ever
| run, and its default is 12 MB — under which a 26 MB custom RustDesk client
| fails at the Livewire endpoint with a redirect and no message on screen. So
| the temp-upload limit tracks the same setting the Client Downloads screen
| validates against.
|
| The full chain, all must allow the file:
|
|   nginx  client_max_body_size   limit + 16 MB  (docker/nginx.conf.template)
|   PHP    post_max_size          limit + 16 MB  (written at boot)
|   PHP    upload_max_filesize    limit + 16 MB  (written at boot)
|   Livewire temporary_file_upload.rules  limit + 1 MB  <- here
|   CortenDesk cortendesk.downloads_max_kb  limit (default 512 MB)
|
| In the Docker image docker/entrypoint.sh renders the nginx and PHP values
| from CORTENDESK_DOWNLOADS_MAX_MB (or the older CORTENDESK_DOWNLOADS_MAX_KB),
| so one variable moves the whole chain. A manual install raises nginx and PHP
| by hand first: past them the request is rejected before Laravel sees it, and
| the operator gets a broken page instead of a validation error.
*/

return [

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        // Deliberately 1 MB looser than cortendesk.downloads_max_kb (same env
        // expression as config/cortendesk.php): a file just over the limit
        // then reaches the component, and the operator gets CortenDesk's own
        // message naming the limit instead of Livewire's silent 422 at the
        // upload endpoint.
        'rules' => ['required', 'file', 'max:'.(1024 + (is_numeric(env('CORTENDESK_DOWNLOADS_MAX_MB'))
            ? (int) env('CORTENDESK_DOWNLOADS_MAX_MB') * 1024
            : (int) env('CORTENDESK_DOWNLOADS_MAX_KB', 512 * 1024)))],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        // Minutes the signed upload URL stays valid. The signature is checked
        // when PHP receives the request, which is after nginx has buffered the
        // whole body, so this must cover the full transfer: 512 MB takes about
        // 35 minutes at 2 Mbit/s.
        'max_upload_time' => 60,
        'cleanup' => true,
    ],

];
