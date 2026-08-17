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
| The full chain, smallest last, all four must allow the file:
|
|   nginx  client_max_body_size  32m    (docker/nginx.conf.template)
|   PHP    post_max_size         32M    (docker/php.ini)
|   PHP    upload_max_filesize   32M    (docker/php.ini)
|   Livewire temporary_file_upload.rules  <- here
|   CortenDesk cortendesk.downloads_max_kb  (config/cortendesk.php, 30 MB)
|
| Raise the two php.ini values and the nginx one together before raising
| CORTENDESK_DOWNLOADS_MAX_KB: past nginx or PHP the request is rejected before
| Laravel sees it, and the operator gets a broken page instead of a validation
| error.
*/

return [

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        // Deliberately 1 MB looser than cortendesk.downloads_max_kb: a file
        // just over the limit then reaches the component, and the operator gets
        // CortenDesk's own message naming the limit instead of Livewire's
        // silent 422 at the upload endpoint.
        'rules' => ['required', 'file', 'max:'.((int) env('CORTENDESK_DOWNLOADS_MAX_KB', 30720) + 1024)],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        // An installer is tens of MB over whatever the operator's uplink is;
        // 5 minutes is not always enough.
        'max_upload_time' => 15,
        'cleanup' => true,
    ],

];
