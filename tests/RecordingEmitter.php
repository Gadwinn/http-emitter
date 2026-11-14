<?php

declare(strict_types=1);

namespace G\Http\Emitter\Tests;

use G\Http\Emitter\Emitter;

/**
 * Emitter recording the header lines instead of sending them: header() cannot be observed
 * from the CLI SAPI.
 */
final class RecordingEmitter extends Emitter
{
    /**
     * @var list<array{string,bool,int}>
     */
    public array $headers = [];
    public bool $headersAlreadySent = false;

    protected function headersSent(?string &$filename = null, ?int &$line = null): bool
    {
        $filename = 'some-file.php';
        $line = 12;

        return $this->headersAlreadySent;
    }

    protected function sendHeader(string $header, bool $replace, int $statusCode = 0): void
    {
        $this->headers[] = [$header, $replace, $statusCode];
    }
}
