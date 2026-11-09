<?php

namespace Git\Engine;

/**
 * Git's smart HTTP protocol, served by `git http-backend` run as a CGI
 * program through symfony/process. The caller has decided who may read or
 * write (it is the only gate: GIT_HTTP_EXPORT_ALL opens every repository of
 * the root to the backend); this one answers one request: the ref
 * advertisement (info/refs), a fetch (git-upload-pack) or a push
 * (git-receive-pack), the CGI headers read first, the body streamed.
 */
class HttpBackend
{
    public const SERVICES = ['info/refs', 'git-upload-pack', 'git-receive-pack'];

    public function __construct(
        private readonly Git $git,
        private readonly RepositoryStore $store,
        private readonly float $timeout = 600.0,
    ) {
    }

    /**
     * @param resource|string|null $input the request's body
     */
    public function serve(
        string $key,
        string $service,
        string $method,
        string $query,
        ?string $contentType,
        ?string $contentEncoding,
        ?int $contentLength,
        mixed $input,
        ?string $remoteUser,
        ?string $gitProtocol = null,
        string $remoteAddr = '127.0.0.1',
        ?int $maxPushBytes = null,
    ): BackendResponse {
        if (!\in_array($service, self::SERVICES, true)) {
            throw GitException::invalid('service', $service);
        }
        if ('info/refs' === $service && !preg_match('/^service=git-(upload|receive)-pack$/', $query)) {
            // The "dumb" protocol is not served.
            return new BackendResponse(403, ['Content-Type' => 'text/plain'], ["Only git's smart HTTP protocol is served.\n"]);
        }
        $path = $this->store->pathOf($key);
        $env = [
            'GIT_PROJECT_ROOT' => $this->store->root(),
            'GIT_HTTP_EXPORT_ALL' => '1',
            'PATH_INFO' => '/'.basename($path).'/'.$service,
            'REQUEST_METHOD' => $method,
            'QUERY_STRING' => $query,
            'CONTENT_TYPE' => (string) $contentType,
            'REMOTE_ADDR' => $remoteAddr,
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ];
        if (null !== $contentLength) {
            $env['CONTENT_LENGTH'] = (string) $contentLength;
        }
        if (null !== $contentEncoding && '' !== $contentEncoding) {
            $env['HTTP_CONTENT_ENCODING'] = $contentEncoding;
        }
        if (null !== $remoteUser) {
            $env['REMOTE_USER'] = $remoteUser;
        }
        if (null !== $gitProtocol && preg_match('/^[a-z0-9=:.,_-]{1,100}$/i', $gitProtocol)) {
            $env['HTTP_GIT_PROTOCOL'] = $gitProtocol;
        }

        // A push larger than what is left is refused by git itself (receive.maxInputSize).
        $config = null !== $maxPushBytes ? ['receive.maxInputSize' => (string) max(1, $maxPushBytes)] : [];
        $process = $this->git->process(null, ['http-backend'], $env, $this->timeout, $config);
        if (null !== $input) {
            $process->setInput($input);
        }
        $process->start();
        $iterator = $process->getIterator($process::ITER_SKIP_ERR);

        // Read by hand: a generator left with `break` would give its current chunk a second time.
        $buffer = '';
        $head = null;
        for ($iterator->rewind(); $iterator->valid(); $iterator->next()) {
            $buffer .= $iterator->current();
            if (false !== ($end = strpos($buffer, "\r\n\r\n"))) {
                $head = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 4);
                $iterator->next();
                break;
            }
            if (\strlen($buffer) > 65536) {
                break;
            }
        }
        if (null === $head) {
            $process->wait();

            return new BackendResponse(500, ['Content-Type' => 'text/plain'], ["The git backend did not answer.\n"]);
        }

        $status = 200;
        $headers = [];
        foreach (explode("\r\n", $head) as $line) {
            [$name, $value] = array_map('trim', array_pad(explode(':', $line, 2), 2, ''));
            if ('' === $name) {
                continue;
            }
            if (0 === strcasecmp($name, 'Status')) {
                $status = (int) $value ?: 200;
                continue;
            }
            $headers[$name] = $value;
        }

        $body = (static function () use ($buffer, $iterator, $process): \Generator {
            if ('' !== $buffer) {
                yield $buffer;
            }
            for (; $iterator->valid(); $iterator->next()) {
                yield $iterator->current();
            }
            $process->wait();
        })();

        return new BackendResponse($status, $headers, $body);
    }
}
