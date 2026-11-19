<?php

declare(strict_types=1);

namespace G\Http\Emitter\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class EmitterTest extends TestCase
{
    /**
     * @param array<string,list<string>> $headers
     */
    private function createResponse(
        string $body = 'Hello World!',
        array $headers = [],
        int $code = 200,
        string $reasonPhrase = 'OK',
        bool $seekable = true
    ): ResponseInterface {
        $normalized = array_change_key_case($headers, CASE_LOWER);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($code);
        $response->method('getReasonPhrase')->willReturn($reasonPhrase);
        $response->method('getProtocolVersion')->willReturn('1.1');
        $response->method('getHeaders')->willReturn($headers);
        $response->method('hasHeader')->willReturnCallback(
            static fn (string $name): bool => isset($normalized[strtolower($name)])
        );
        $response->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => implode(', ', $normalized[strtolower($name)] ?? [])
        );
        $response->method('getBody')->willReturn(new StringStream($body, $seekable));

        return $response;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function emit(ResponseInterface $response, ?RecordingEmitter $emitter = null, array $options = []): string
    {
        $emitter ??= new RecordingEmitter($options);

        ob_start();
        try {
            $emitter->emit($response);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    public function testEmitStatusLine(): void
    {
        $emitter = new RecordingEmitter();
        $this->emit($this->createResponse(code: 404, reasonPhrase: 'Not Found'), $emitter);

        $this->assertSame(['HTTP/1.1 404 Not Found', true, 404], $emitter->headers[0]);
    }

    public function testEmitStatusLineWithoutReasonPhrase(): void
    {
        $emitter = new RecordingEmitter();
        $this->emit($this->createResponse(code: 299, reasonPhrase: ''), $emitter);

        $this->assertSame(['HTTP/1.1 299', true, 299], $emitter->headers[0]);
    }

    public function testEmitHeadersOneLinePerValue(): void
    {
        $emitter = new RecordingEmitter();
        $this->emit($this->createResponse(headers: [
            'Content-Type' => ['text/html; charset=UTF-8'],
            'X-Multi' => ['a', 'b'],
        ]), $emitter);

        $this->assertSame(
            [
                ['Content-Type: text/html; charset=UTF-8', true, 0],
                ['X-Multi: a', true, 0],
                ['X-Multi: b', false, 0],
            ],
            array_slice($emitter->headers, 1)
        );
    }

    public function testCookiesAreNeverMergedNorReplaced(): void
    {
        $emitter = new RecordingEmitter();
        $this->emit($this->createResponse(headers: [
            'Set-Cookie' => ['a=1; Expires=Wed, 21 Oct 2026 07:28:00 GMT', 'b=2'],
        ]), $emitter);

        $this->assertSame(
            [
                ['Set-Cookie: a=1; Expires=Wed, 21 Oct 2026 07:28:00 GMT', false, 0],
                ['Set-Cookie: b=2', false, 0],
            ],
            array_slice($emitter->headers, 1)
        );
    }

    public function testEmitBody(): void
    {
        $this->assertSame('Hello World!', $this->emit($this->createResponse()));
    }

    public function testEmitBodyInChunks(): void
    {
        $response = $this->createResponse('0123456789');

        $this->assertSame('0123456789', $this->emit($response, options: ['maxBufferLength' => 4]));
        /** @var StringStream $body */
        $body = $response->getBody();
        $this->assertSame([4, 4, 4], $body->readLengths);
    }

    public function testEmitNonSeekableBodyInChunks(): void
    {
        $response = $this->createResponse('0123456789', seekable: false);

        $this->assertSame('0123456789', $this->emit($response, options: ['maxBufferLength' => 4]));
        /** @var StringStream $body */
        $body = $response->getBody();
        $this->assertSame([4, 4, 4], $body->readLengths);
    }

    /**
     * @return array<string,array{int}>
     */
    public static function statusCodeWithoutBodyProvider(): array
    {
        return ['100' => [100], '103' => [103], '204' => [204], '205' => [205], '304' => [304]];
    }

    #[DataProvider('statusCodeWithoutBodyProvider')]
    public function testNoBodyForStatusCode(int $code): void
    {
        $this->assertSame('', $this->emit($this->createResponse(code: $code, reasonPhrase: '')));
    }

    /**
     * @return array<string,array{string,bool,int,string}>
     */
    public static function rangeProvider(): array
    {
        return [
            'start of body'              => ['bytes 0-3/10', true, 8192, '0123'],
            'middle of body'             => ['bytes 2-5/10', true, 8192, '2345'],
            'unknown total length'       => ['bytes 2-5/*', true, 8192, '2345'],
            'range larger than a chunk'  => ['bytes 1-8/10', true, 3, '12345678'],
            'range ending past the body' => ['bytes 8-20/10', true, 8192, '89'],
            'non seekable body'          => ['bytes 2-5/10', false, 8192, '2345'],
            'unsatisfied range'          => ['bytes */10', true, 8192, '0123456789'],
            'unit is case-insensitive'   => ['BYTES 2-5/10', true, 8192, '2345'],
            'last before first position' => ['bytes 5-2/10', true, 8192, '0123456789'],
            'other unit left as it is'   => ['items 0-3/10', true, 8192, '0123456789'],
            'no size multiplier'         => ['kilobytes 0-0/1', true, 8192, '0123456789'],
            'unit starting with t'       => ['tokens 0-1/10', false, 8192, '0123456789'],
        ];
    }

    #[DataProvider('rangeProvider')]
    public function testEmitContentRange(string $contentRange, bool $seekable, int $chunk, string $expected): void
    {
        $response = $this->createResponse(
            '0123456789',
            ['Content-Range' => [$contentRange]],
            206,
            'Partial Content',
            $seekable
        );

        $this->assertSame($expected, $this->emit($response, options: ['maxBufferLength' => $chunk]));
    }

    public function testHeadersAlreadySentThrowsByDefault(): void
    {
        $emitter = new RecordingEmitter();
        $emitter->headersAlreadySent = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('some-file.php');

        $this->emit($this->createResponse(), $emitter);
    }

    public function testHeadersAlreadySentCanBeIgnored(): void
    {
        $emitter = new RecordingEmitter(['errorOnHeadersSent' => RecordingEmitter::ERROR_ON_HEADERS_SENT_IGNORE]);
        $emitter->headersAlreadySent = true;

        $this->assertSame('Hello World!', $this->emit($this->createResponse(), $emitter));
        $this->assertSame([], $emitter->headers);
    }

    public function testHeadersAlreadySentCanTriggerWarning(): void
    {
        $emitter = new RecordingEmitter([
            'errorOnHeadersSent' => RecordingEmitter::ERROR_ON_HEADERS_SENT_TRIGGER_WARNING,
        ]);
        $emitter->headersAlreadySent = true;

        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = [$level, $message];
            return true;
        });
        try {
            $output = $this->emit($this->createResponse(), $emitter);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('Hello World!', $output);
        $this->assertCount(1, $warnings);
        $this->assertSame(E_USER_WARNING, $warnings[0][0]);
    }

    /**
     * @return array<string,array{mixed}>
     */
    public static function invalidBufferLengthProvider(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'not a number' => ['abc'], 'null' => [null]];
    }

    #[DataProvider('invalidBufferLengthProvider')]
    public function testInvalidMaxBufferLengthIsRejected(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RecordingEmitter(['maxBufferLength' => $value]);
    }
}
