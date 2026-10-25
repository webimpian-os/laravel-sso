<?php

namespace Webimpian\Sso;

use Illuminate\Contracts\Support\Responsable;
use RuntimeException;

class SsoException extends RuntimeException implements Responsable
{
    public ?string $reasonCode = null;

    public static function refuse(string $message): self
    {
        return new self($message, 403);
    }

    // The portal keys its "Continue anyway" button on this code.
    public static function deletedAccount(): self
    {
        $e = self::refuse('This email belongs to a deleted account in this app. Ask an admin of this app to sort it out.');
        $e->reasonCode = 'deleted_account';

        return $e;
    }

    // Refusals are routine; portal faults are logged where they happen.
    public function report(): void {}

    public function toResponse($request)
    {
        return response()->view('sso::message', ['message' => $this->getMessage()], $this->getCode() ?: 400);
    }
}
