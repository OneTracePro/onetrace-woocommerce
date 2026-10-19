<?php

declare(strict_types=1);

namespace OneTrace\WooCommerce\Tests\Support;

use OneTrace\Http\Request;
use OneTrace\Http\Response;
use OneTrace\Http\Transport;

/**
 * Records requests to the platform and answers with queued responses (202 by default).
 */
final class RecordingTransport implements Transport
{
    /** @var list<array{method: string, path: string, body: mixed, auth: string, idempotency: string}> */
    public $requests = [];

    /** @var list<Response> */
    private $responses = [];

    /** @var (callable(Request): ?Response)|null */
    public $responder;

    public function push(int $status, array $body = []): void
    {
        $this->responses[] = new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
    }

    public function send(Request $request): Response
    {
        $headers = array_change_key_case($request->getHeaders());
        $this->requests[] = [
            'method' => $request->getMethod(),
            'path' => (string) parse_url($request->getUrl(), PHP_URL_PATH),
            'body' => json_decode((string) $request->getBody(), true),
            'auth' => (string) ($headers['authorization'] ?? ''),
            'idempotency' => (string) ($headers['idempotency-key'] ?? ''),
        ];

        if ($this->responder !== null && ($response = ($this->responder)($request)) !== null) {
            return $response;
        }

        return array_shift($this->responses) ?? new Response(202, ['content-type' => 'application/json'], '{"accepted":1,"duplicates":0}');
    }

    /**
     * Requests to one path, e.g. POST /api/v1/emails.
     *
     * @return list<array{method: string, path: string, body: mixed, auth: string, idempotency: string}>
     */
    public function to(string $method, string $path): array
    {
        return array_values(array_filter($this->requests, static function (array $request) use ($method, $path): bool {
            return $request['method'] === $method && $request['path'] === $path;
        }));
    }

    /**
     * Messages of all batch requests.
     *
     * @return list<array<string, mixed>>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->requests as $request) {
            if ($request['path'] === '/api/v1/batch') {
                $messages = array_merge($messages, (array) ($request['body']['batch'] ?? []));
            }
        }

        return $messages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(string $name): array
    {
        return array_values(array_filter($this->messages(), static function (array $message) use ($name): bool {
            return ($message['event'] ?? $message['type']) === $name;
        }));
    }
}
