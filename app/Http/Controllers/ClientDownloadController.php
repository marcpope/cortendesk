<?php

namespace App\Http\Controllers;

use App\Models\ClientDownload;
use App\Support\ClientPlatform;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The public client-download page and file streamer.
 *
 * Both routes are deliberately unauthenticated (routes/web.php): the whole
 * point is a link an operator can send to somebody who has no console account,
 * and the sign-in page shows the same icons for a technician standing at a
 * fresh machine. What that costs is that anything published here is public, so:
 *
 *   - only is_published rows are ever visible or fetchable;
 *   - the row is looked up by primary key, never by a path from the request,
 *     so there is no traversal surface;
 *   - the response is always an attachment with nosniff, so an uploaded file
 *     can never be coaxed into executing in the browser;
 *   - both routes are throttled (see the route definitions).
 *
 * With CORTENDESK_DOWNLOADS_ACCEL set (the Docker image sets it), nginx sends
 * the file itself via X-Accel-Redirect. Streamed through PHP, nginx buffers
 * each response to a temp file first, up to 1 GB per concurrent download.
 */
class ClientDownloadController extends Controller
{
    public function index(): View
    {
        return view('downloads.index', [
            'downloads' => ClientDownload::published()->ordered()->get()
                ->sortBy(fn (ClientDownload $d) => array_search($d->platform, ClientPlatform::PLATFORMS, true))
                ->values(),
        ]);
    }

    public function show(ClientDownload $download): Response|StreamedResponse
    {
        // Route-model binding does not know about the scope, and an
        // unpublished build must be a 404 rather than a 403 — an outsider
        // learns nothing about what is staged.
        abort_unless($download->is_published, 404);

        $disk = Storage::disk(ClientDownload::DISK);

        abort_unless($disk->exists($download->filename), 404);

        // Not a real counter (no locking, and a resumed download counts twice);
        // it exists so an operator can see which build people actually take.
        // Never at the expense of the download itself: this is the only write on
        // an otherwise read-only public route, so a read-only replica, a locked
        // SQLite file or a full disk would turn every install attempt into a
        // 500. Report and hand over the file.
        try {
            $download->increment('download_count');
        } catch (Throwable $e) {
            report($e);
        }

        $accel = (string) config('cortendesk.downloads_accel');
        if ($accel !== '') {
            return response('', 200, [
                'X-Accel-Redirect' => rtrim($accel, '/').'/'.rawurlencode($download->filename),
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $download->original_name,
                    $this->asciiFallback($download->original_name),
                ),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return $disk->download($download->filename, $download->original_name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Content-Disposition needs an ASCII filename beside the UTF-8 one. */
    private function asciiFallback(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7e]/u', '_', $name)
            ?? preg_replace('/[^\x20-\x7e]/', '_', $name);

        return str_replace(['/', '\\', '%'], '_', $ascii);
    }
}
