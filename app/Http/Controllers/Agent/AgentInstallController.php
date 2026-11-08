<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Support\AgentInstall;
use Illuminate\Http\Response;

/**
 * Serves install.sh and install.ps1 with this console's address and the
 * agent build it pins (resources/agent/manifest.json) filled in. The scripts
 * check the download's sha256 before running it.
 */
class AgentInstallController extends Controller
{
    public function sh(): Response
    {
        $cases = [];
        foreach (AgentInstall::files() as $key => $file) {
            if (str_starts_with($key, 'windows-')) {
                continue;
            }
            $cases[] = "    {$key}) URL=".self::shQuote($file['url']).'; SHA='.self::shQuote($file['sha256']).' ;;';
        }

        return $this->script('install.sh', ['@CASES@' => implode("\n", $cases)], 'text/x-shellscript');
    }

    public function ps1(): Response
    {
        $files = [];
        foreach (AgentInstall::files() as $key => $file) {
            if (! str_starts_with($key, 'windows-')) {
                continue;
            }
            $arch = substr($key, strlen('windows-'));
            $files[] = "    '{$arch}' = @{ Url = ".self::psQuote($file['url']).'; Sha256 = '.self::psQuote($file['sha256']).' }';
        }

        return $this->script('install.ps1', ['@PS_FILES@' => implode("\n", $files)], 'text/plain');
    }

    private function script(string $name, array $replace, string $type): Response
    {
        $body = strtr((string) file_get_contents(resource_path('agent/'.$name)), $replace + [
            // Quoted in the templates; a quote in the URL would end the string.
            '@SERVER@' => str_replace(["'", '"', '`', '$'], '', AgentInstall::serverUrl()),
            '@VERSION@' => AgentInstall::version(),
        ]);

        return response($body, 200, [
            'Content-Type' => $type.'; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function shQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private static function psQuote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
