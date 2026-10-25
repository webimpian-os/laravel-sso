<?php

namespace Webimpian\Sso\Tests;

use Illuminate\Database\Eloquent\SoftDeletes;

class SoftUser extends User
{
    use SoftDeletes;

    protected $table = 'users';
}
