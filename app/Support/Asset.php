<?php

namespace App\Support;

class Asset
{
    /**
     * Versioned URL for a first-party static asset.
     *
     * Our CSS/JS filenames never change, so a browser that cached them under a
     * previous release keeps serving those bytes against freshly upgraded HTML
     * — indefinitely, since nothing in the URL says otherwise. The visible
     * result is a console with the theme half-applied: Bootstrap loads, our
     * overrides do not, so the accent colour reverts to Bootstrap blue and
     * custom layouts collapse. It looks like a browser bug and is not one.
     *
     * Keyed on the release version rather than filemtime: the version is the
     * same for every worker and every container, so a multi-replica deployment
     * cannot hand different clients different URLs for identical bytes.
     */
    public static function url(string $path): string
    {
        return asset($path).'?v='.config('cortendesk.api_version');
    }
}
