<?php

namespace Silber\Bouncer\Conductors;

class ForbidsAbilities
{
    use Concerns\AssociatesAbilities;

    /**
     * The authority to be forbidden from the abilities.
     *
     * @var \MongoDB\Laravel\Eloquent\Model|string|null
     */
    protected $authority;

    /**
     * Whether the associated abilities should be forbidden abilities.
     *
     * @var bool
     */
    protected $forbidding = true;

    /**
     * Constructor.
     *
     * @param  \MongoDB\Laravel\Eloquent\Model|string|null  $authority
     */
    public function __construct($authority = null)
    {
        $this->authority = $authority;
    }
}
