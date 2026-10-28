<?php

namespace Webimpian\Sso\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

// KeepSsoSession does the work, so a ping can't revive an idle or ended session.
class KeepAliveController
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
