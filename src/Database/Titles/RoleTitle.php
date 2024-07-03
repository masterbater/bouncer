<?php

namespace Silber\Bouncer\Database\Titles;

use MongoDB\Laravel\Eloquent\Model;

class RoleTitle extends Title
{
    public function __construct(Model $role)
    {
        $this->title = $this->humanize($role->name);
    }
}
