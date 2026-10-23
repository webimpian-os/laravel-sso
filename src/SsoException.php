<?php

namespace Webimpian\Sso;

use Illuminate\Contracts\Support\Responsable;
use RuntimeException;

class SsoException extends RuntimeException implements Responsable
{
    public static function refuse(string $message): self
    {
        return new self($message, 403);
    }

    // Refusals are routine; portal faults are logged where they happen.
    public function report(): void {}

    public function toResponse($request)
    {
        return response()->view('sso::message', ['message' => $this->getMessage()], $this->getCode() ?: 400);
    }
}
