<?php

declare(strict_types=1);

namespace G\Http\Emitter;

use Psr\Http\Message\ResponseInterface;

interface EmitterInterface
{
    /**
     * Emit the response (header, body)
     *
     * @param ResponseInterface $response
     *
     * @return void
     */
    public function emit(ResponseInterface $response): void;
}
