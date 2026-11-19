<?php

declare(strict_types=1);

namespace G\Http\Emitter;

use Psr\Http\Message\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;

class Emitter implements EmitterInterface
{
    public const ERROR_ON_HEADERS_SENT_IGNORE = 0;
    public const ERROR_ON_HEADERS_SENT_TRIGGER_WARNING = E_USER_WARNING;
    /**
     * @deprecated Triggering E_USER_ERROR is deprecated since PHP 8.4: this mode now throws
     *     a RuntimeException, exactly like ERROR_ON_HEADERS_SENT_THROW_EXCEPTION.
     */
    public const ERROR_ON_HEADERS_SENT_TRIGGER_ERROR = E_USER_ERROR;
    public const ERROR_ON_HEADERS_SENT_THROW_EXCEPTION = 1;

    protected const WILDCARD = '*';
    protected const HEADER_SET_COOKIE = 'set-cookie';

    /**
     * @var array<string,mixed>
     */
    protected array $options = [
        'errorOnHeadersSent' => self::ERROR_ON_HEADERS_SENT_THROW_EXCEPTION,
        'maxBufferLength' => 8192,
    ];

    /**
     * `bytes` is the only range unit defined by RFC 9110 (§14.1, case-insensitive);
     * a Content-Range in any other unit is left to the application and the body is
     * emitted as it is.
     */
    protected const REGEX_CONTENT_RANGE = '/^bytes[ \t]+(?P<start>\d+)-(?P<end>\d+)\/(?P<length>\d+|\*)$/iD';
    protected const STATUS_CODES_NO_BODY = ['1\d\d', '204', '205', '304'];

    /**
     * @param array<string,mixed> $options
     *
     * @throws InvalidArgumentException
     */
    public function __construct(array $options = [])
    {
        if (array_key_exists('maxBufferLength', $options)) {
            $maxBufferLength = $options['maxBufferLength'];
            if (!is_numeric($maxBufferLength) || (int) $maxBufferLength < 1) {
                throw new InvalidArgumentException('Option "maxBufferLength" must be a positive integer');
            }
            $options['maxBufferLength'] = (int) $maxBufferLength;
        }

        $this->options = array_merge($this->options, $options);
    }

    public function emit(ResponseInterface $response): void
    {
        if ($this->headersSent($filename, $line)) {
            $this->error('Unable to emit headers. Headers sent in file=' . $filename . ' on line=' . $line);
        } else {
            $this->emitStatusLine($response);
            $this->emitHeaders($response);
        }

        $this->flush();
        $this->emitBody($response);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /**
     * Emit the response status line
     *
     * @param ResponseInterface $response
     *
     * @return void
     */
    protected function emitStatusLine(ResponseInterface $response): void
    {
        $statusLine = sprintf(
            'HTTP/%s %d%s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase() ? ' ' . $response->getReasonPhrase() : ''
        );
        $this->sendHeader($statusLine, true, $response->getStatusCode());
    }

    /**
     * Emit the response headers
     *
     * @param ResponseInterface $response
     *
     * @return void
     */
    protected function emitHeaders(ResponseInterface $response): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            $name = (string) $name;
            // The first value replaces a header already set through header() (PHP defaults,
            // session...), except for cookies, which must all be kept.
            $replace = strtolower($name) !== self::HEADER_SET_COOKIE;
            // One line per value: joining them with commas would break Set-Cookie.
            foreach ($values as $value) {
                $this->sendHeader($name . ': ' . $value, $replace);
                $replace = false;
            }
        }
    }

    /**
     * Tell whether the headers were already sent (wrapper around headers_sent()).
     *
     * @param-out string $filename
     * @param-out int $line
     */
    protected function headersSent(?string &$filename = null, ?int &$line = null): bool
    {
        return headers_sent($filename, $line);
    }

    /**
     * Send one raw header line (wrapper around header()).
     */
    protected function sendHeader(string $header, bool $replace, int $statusCode = 0): void
    {
        header($header, $replace, $statusCode);
    }

    /**
     * Flush the output buffer
     *
     * @return void
     */
    protected function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
    }

    /**
     * Emit the response body
     *
     * @param ResponseInterface $response
     *
     * @return void
     * @throws RuntimeException
     */
    protected function emitBody(ResponseInterface $response): void
    {
        $regexStatusCodesNoBody = '/^(?:' . implode('|', self::STATUS_CODES_NO_BODY) . ')$/';
        $statusCode = $response->getStatusCode();
        if (preg_match($regexStatusCodesNoBody, (string) $statusCode)) {
            return;
        }

        $range = $this->getRange($response);

        $body = $response->getBody();

        if (!$body->isSeekable()) {
            if ($range) {
                echo substr($body->getContents(), $range['start'], $range['size']);
                return;
            }

            // Stream it chunk by chunk rather than loading it whole in memory
            while (!$body->eof()) {
                echo $body->read($this->options['maxBufferLength']);
            }
            return;
        }

        if ($range) {
            $pos = $range['start'];
            $body->seek($pos);
            while (!$body->eof() && $pos <= $range['end']) {
                if ($pos + $this->options['maxBufferLength'] > $range['end']) {
                    echo $body->read($range['end'] - $pos + 1);
                    break;
                }

                echo $body->read($this->options['maxBufferLength']);
                $pos = $body->tell();
            }
        } else {
            $body->rewind();
            while (!$body->eof()) {
                echo $body->read($this->options['maxBufferLength']);
            }
        }
    }

    /**
     * @param ResponseInterface $response
     *
     * @return array<string,mixed> Empty when there is no usable `bytes` range
     */
    protected function getRange(ResponseInterface $response): array
    {
        if (!$response->hasHeader('Content-Range')) {
            return [];
        }

        $contentRange = $response->getHeaderLine('Content-Range');
        if (!$contentRange || !preg_match(self::REGEX_CONTENT_RANGE, $contentRange, $matches)) {
            return [];
        }

        $range = [
            'unit' => 'bytes',
            'start' => (int) $matches['start'],
            'end' => (int) $matches['end'],
            'length' => $matches['length'] !== self::WILDCARD ? (int) $matches['length'] : 0,
        ];

        // RFC 9110 §14.4: a range whose last position is before its first is invalid
        if ($range['end'] < $range['start']) {
            return [];
        }

        $range['size'] = $range['end'] - $range['start'] + 1;
        return $range;
    }

    /**
     * @param string $errorMessage
     *
     * @return void
     * @throws RuntimeException
     */
    protected function error(string $errorMessage): void
    {
        switch ($this->options['errorOnHeadersSent']) {
            case self::ERROR_ON_HEADERS_SENT_TRIGGER_WARNING:
                trigger_error($errorMessage, E_USER_WARNING);
                break;
            case self::ERROR_ON_HEADERS_SENT_TRIGGER_ERROR:
            case self::ERROR_ON_HEADERS_SENT_THROW_EXCEPTION:
                throw new RuntimeException($errorMessage);
            default:
                // Do nothing
        }
    }
}
