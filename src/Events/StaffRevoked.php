<?php

namespace Webimpian\Sso\Events;

use Illuminate\Database\Eloquent\Model;

class StaffRevoked
{
    public function __construct(public Model $user) {}
}
