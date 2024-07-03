<?php

namespace Silber\Bouncer\Conductors;

class GivesAbilities
{
    use Concerns\AssociatesAbilities;

    /**
     * The authority to be given abilities.
     *
     * @var \MongoDB\Laravel\Eloquent\Model|string|null
     */
    protected $authority;

    /**
     * Whether the associated abilities should be forbidden abilities.
     *
     * @var bool
     */
    protected $forbidding = false;

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
