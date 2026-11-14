<?php

declare(strict_types=1);

namespace G\Http\Emitter\Tests;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Minimal in-memory PSR-7 stream, so the tests do not depend on a PSR-7 implementation.
 */
final class StringStream implements StreamInterface
{
    private int $position = 0;
    /**
     * @var list<int>
     */
    public array $readLengths = [];

    public function __construct(private string $content, private bool $seekable = true)
    {
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return strlen($this->content);
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->content);
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->seekable) {
            throw new RuntimeException('Stream not seekable');
        }
        $this->position = $offset;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Stream not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $this->readLengths[] = $length;
        $chunk = substr($this->content, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $chunk = substr($this->content, $this->position);
        $this->position = strlen($this->content);

        return $chunk;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
