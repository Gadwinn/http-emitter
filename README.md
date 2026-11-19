# HTTP Emitter

A minimal, PSR-7 compliant HTTP response emitter for PHP. Sends status line, headers, and body to the client with support for partial content (`Content-Range`), chunked streaming, and FastCGI.

## Tech Stack

- **PHP** 8.3+
- **PSR-7** - `psr/http-message` `^1.1 || ^2.0`

## Installation

```bash
composer require gadwinn/http-emitter
```

## Usage

### Basic emission

Pass any `Psr\Http\Message\ResponseInterface` to `emit()`. The emitter handles the status line, all response headers, and the body stream.

```php
use G\Http\Emitter\Emitter;

$emitter = new Emitter();
$emitter->emit($response);
```

Each header value is sent on its own line, so several `Set-Cookie` headers are emitted correctly. A header of the response replaces one previously set with `header()`, except cookies, which are all kept.

---

### Constructor options

```php
use G\Http\Emitter\Emitter;

$emitter = new Emitter([
    'errorOnHeadersSent' => Emitter::ERROR_ON_HEADERS_SENT_THROW_EXCEPTION,
    'maxBufferLength'    => 8192,
]);
```

#### `errorOnHeadersSent`

Controls the behavior when `headers_sent()` is `true` at emission time.

| Constant | Behavior |
|---|---|
| `Emitter::ERROR_ON_HEADERS_SENT_IGNORE` | Silent - no action taken |
| `Emitter::ERROR_ON_HEADERS_SENT_TRIGGER_WARNING` | `trigger_error($msg, E_USER_WARNING)` |
| `Emitter::ERROR_ON_HEADERS_SENT_TRIGGER_ERROR` | Deprecated (`E_USER_ERROR` is deprecated since PHP 8.4): throws like the default |
| `Emitter::ERROR_ON_HEADERS_SENT_THROW_EXCEPTION` | `throw new RuntimeException($msg)` *(default)* |

```php
// Downgrade to a warning instead of throwing
$emitter = new Emitter([
    'errorOnHeadersSent' => Emitter::ERROR_ON_HEADERS_SENT_TRIGGER_WARNING,
]);
```

#### `maxBufferLength`

Chunk size in bytes used when streaming the body. Defaults to `8192`. Must be a positive integer, otherwise an `InvalidArgumentException` is thrown.

```php
$emitter = new Emitter(['maxBufferLength' => 65536]); // 64 KB chunks
```

---

### End of the response

After the body, the emitter calls `fastcgi_finish_request()` when the SAPI provides it
(PHP-FPM, FrankenPHP): the client receives the complete response at once, and the script
goes on (cleanup, `finally` blocks, the reset of services in a worker loop) without making it
wait. Anything echoed after `emit()` is therefore not sent; emit exactly one response per request.
Checked in FrankenPHP worker mode, where the function exists and finishes the current request
only: the worker keeps serving the next ones.

---

### Partial content (Content-Range)

When the response carries a `Content-Range` header in `bytes` (the only range unit defined by RFC 9110), the emitter slices the body to the declared range. Any other unit (`items 0-9/100`...) is left to the application: the body is emitted as it is.

```php
// The emitter reads Content-Range from the response and emits only the requested slice.
// Example header produced by your PSR-7 layer:
//   Content-Range: bytes 0-1023/4096

$emitter->emit($partialResponse); // streams bytes 0–1023 only
```

---

### Custom emitter

Extend `Emitter` to override any protected step (`emitStatusLine`, `emitHeaders`, `emitBody`, `flush`, or the low-level `sendHeader` / `headersSent` wrappers) while keeping the rest of the pipeline intact.

```php
use G\Http\Emitter\Emitter;
use Psr\Http\Message\ResponseInterface;

final class LoggingEmitter extends Emitter
{
    protected function emitBody(ResponseInterface $response): void
    {
        // pre-emission hook
        parent::emitBody($response);
        // post-emission hook
    }
}
```

Alternatively, bind to the `EmitterInterface` contract for full replacement:

```php
use G\Http\Emitter\EmitterInterface;

final class NullEmitter implements EmitterInterface
{
    public function emit(ResponseInterface $response): void {}
}
```

## License

MIT, see [LICENSE](LICENSE).
