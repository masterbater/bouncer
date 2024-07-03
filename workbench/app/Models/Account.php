<?php

namespace Workbench\App\Models;

use MongoDB\Laravel\Eloquent\Model as Model;
use Silber\Bouncer\Database\Concerns\Authorizable;
use Silber\Bouncer\Database\HasRolesAndAbilities;

class Account extends Model
{
    use Authorizable, HasRolesAndAbilities;

    protected $guarded = [];
}
