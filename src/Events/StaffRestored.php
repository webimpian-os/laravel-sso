<?php

namespace Webimpian\Sso\Events;

use Illuminate\Database\Eloquent\Model;

class StaffRestored
{
    public function __construct(public Model $user) {}
}
