<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;

class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    public function __construct(
        private string $content = '',
        private int $status = 200,
        string $contentType = 'text/html; charset=UTF-8'
    ) {
        $this->headers['Content-Type'] = $contentType;
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status);
    }

    /** @throws JsonException */
    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            'application/json; charset=UTF-8'
        );
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $response = new self('', $status);
        return $response->header('Location', $url);
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        echo $this->content;
    }
}
