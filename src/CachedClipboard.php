<?php

namespace Silber\Bouncer;

use Illuminate\Cache\TaggedCache;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Eloquent\Collection;
use MongoDB\Laravel\Eloquent\Model as Model;
use Illuminate\Support\Collection as BaseCollection;
use Silber\Bouncer\Database\Models;

class CachedClipboard extends BaseClipboard implements Contracts\CachedClipboard
{
    /**
     * The tag used for caching.
     *
     * @var string
     */
    protected $tag = 'silber-bouncer';

    /**
     * The cache store.
     *
     * @var \Illuminate\Contracts\Cache\Store
     */
    protected $cache;

    /**
     * Constructor.
     */
    public function __construct(Store $cache)
    {
        $this->setCache($cache);
    }

    /**
     * Set the cache instance.
     *
     * @return $this
     */
    public function setCache(Store $cache)
    {
        if (method_exists($cache, 'tags')) {
            $cache = $cache->tags($this->tag());
        }

        $this->cache = $cache;

        return $this;
    }

    /**
     * Get the cache instance.
     *
     * @return \Illuminate\Contracts\Cache\Store
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * Determine if the given authority has the given ability, and return the ability ID.
     *
     * @param  string  $ability
     * @param  \MongoDB\Laravel\Eloquent\Model|string|null  $model
     * @return int|bool|null
     */
    public function checkGetId(Model $authority, $ability, $model = null)
    {
        $applicable = $this->compileAbilityIdentifiers($ability, $model);

        // We will first check if any of the applicable abilities have been forbidden.
        // If so, we'll return false right away, so as to not pass the check. Then,
        // we'll check if any of them have been allowed & return the matched ID.
        $forbiddenId = $this->findMatchingAbility(
            $this->getForbiddenAbilities($authority),
            $applicable,
            $model,
            $authority
        );

        if ($forbiddenId) {
            return false;
        }

        return $this->findMatchingAbility(
            $this->getAbilities($authority),
            $applicable,
            $model,
            $authority
        );
    }

    /**
     * Determine if any of the abilities can be matched against the provided applicable ones.
     *
     * @param  \Illuminate\Support\Collection  $abilities
     * @param  \Illuminate\Support\Collection  $applicable
     * @param  \MongoDB\Laravel\Eloquent\Model  $model
     * @param  \MongoDB\Laravel\Eloquent\Model  $authority
     * @return int|null
     */
    protected function findMatchingAbility($abilities, $applicable, $model, $authority)
    {
        $abilities = $abilities->toBase()->pluck('identifier', 'id');

        if ($id = $this->getMatchedAbilityId($abilities, $applicable)) {
            return $id;
        }

        if ($this->isOwnedBy($authority, $model)) {
            return $this->getMatchedAbilityId(
                $abilities,
                $applicable->map(function ($identifier) {
                    return $identifier . '-owned';
                })
            );
        }
    }

    /**
     * Get the ID of the ability that matches one of the applicable abilities.
     *
     * @param  \Illuminate\Support\Collection  $abilityMap
     * @param  \Illuminate\Support\Collection  $applicable
     * @return int|null
     */
    protected function getMatchedAbilityId($abilityMap, $applicable)
    {
        foreach ($abilityMap as $id => $identifier) {
            if ($applicable->contains($identifier)) {
                return $id;
            }
        }
    }

    /**
     * Compile a list of ability identifiers that match the provided parameters.
     *
     * @param  string  $ability
     * @param  \MongoDB\Laravel\Eloquent\Model|string|null  $model
     * @return \Illuminate\Support\Collection
     */
    protected function compileAbilityIdentifiers($ability, $model)
    {
        $identifiers = new BaseCollection(
            is_null($model)
                ? [$ability, '*-*', '*']
                : $this->compileModelAbilityIdentifiers($ability, $model)
        );

        return $identifiers->map(function ($identifier) {
            return strtolower($identifier);
        });
    }

    /**
     * Compile a list of ability identifiers that match the given model.
     *
     * @param  string  $ability
     * @param  \MongoDB\Laravel\Eloquent\Model|string  $model
     * @return array
     */
    protected function compileModelAbilityIdentifiers($ability, $model)
    {
        if ($model === '*') {
            return ["{$ability}-*", '*-*'];
        }

        $model = $model instanceof Model ? $model : new $model;

        $type = $model->getMorphClass();

        $abilities = [
            "{$ability}-{$type}",
            "{$ability}-*",
            "*-{$type}",
            '*-*',
        ];

        if ($model->exists) {
            $abilities[] = "{$ability}-{$type}-{$model->getKey()}";
            $abilities[] = "*-{$type}-{$model->getKey()}";
        }

        return $abilities;
    }

    /**
     * Get the given authority's abilities.
     *
     * @param  bool  $allowed
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAbilities(Model $authority, $allowed = true)
    {
        $key = $this->getCacheKey($authority, 'abilities', $allowed);

        if (is_array($abilities = $this->cache->get($key))) {
            return $this->deserializeAbilities($abilities);
        }

        $abilities = $this->getFreshAbilities($authority, $allowed);

        $this->cache->forever($key, $this->serializeAbilities($abilities));

        return $abilities;
    }

    /**
     * Get a fresh copy of the given authority's abilities.
     *
     * @param  bool  $allowed
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getFreshAbilities(Model $authority, $allowed)
    {
        return parent::getAbilities($authority, $allowed);
    }

    /**
     * Get the given authority's roles' IDs and names.
     *
     * @return array
     */
    public function getRolesLookup(Model $authority)
    {
        $key = $this->getCacheKey($authority, 'roles');

        return $this->sear($key, function () use ($authority) {
            return parent::getRolesLookup($authority);
        });
    }

    /**
     * Get an item from the cache, or store the default value forever.
     *
     * @param  string  $key
     * @return mixed
     */
    protected function sear($key, callable $callback)
    {
        if (is_null($value = $this->cache->get($key))) {
            $this->cache->forever($key, $value = $callback());
        }

        return $value;
    }

    /**
     * Clear the cache.
     *
     * @param  null|\MongoDB\Laravel\Eloquent\Model  $authority
     * @return $this
     */
    public function refresh($authority = null)
    {
        if (!is_null($authority)) {
            return $this->refreshFor($authority);
        }

        if ($this->cache instanceof TaggedCache) {
            $this->cache->flush();
        } else {
            $this->refreshAllIteratively();
        }

        return $this;
    }

    /**
     * Clear the cache for the given authority.
     *
     * @return $this
     */
    public function refreshFor(Model $authority)
    {
        $this->cache->forget($this->getCacheKey($authority, 'abilities', true));
        $this->cache->forget($this->getCacheKey($authority, 'abilities', false));
        $this->cache->forget($this->getCacheKey($authority, 'roles'));

        return $this;
    }

    /**
     * Refresh the cache for all roles and users, iteratively.
     *
     * @return void
     */
    protected function refreshAllIteratively()
    {
        foreach (Models::user()->all() as $user) {
            $this->refreshFor($user);
        }

        foreach (Models::role()->all() as $role) {
            $this->refreshFor($role);
        }
    }

    /**
     * Get the cache key for the given model's cache type.
     *
     * @param  string  $type
     * @param  bool  $allowed
     * @return string
     */
    protected function getCacheKey(Model $model, $type, $allowed = true)
    {
        return implode('-', [
            $this->tag(),
            $type,
            $model->getMorphClass(),
            $model->getKey(),
            $allowed ? 'a' : 'f',
        ]);
    }

    /**
     * Get the cache tag.
     *
     * @return string
     */
    protected function tag()
    {
        return Models::scope()->appendToCacheKey($this->tag);
    }

    /**
     * Deserialize an array of abilities into a collection of models.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function deserializeAbilities(array $abilities)
    {
        return Models::ability()->hydrate($abilities);
    }

    /**
     * Serialize a collection of ability models into a plain array.
     *
     * @return array
     */
    protected function serializeAbilities(Collection $abilities)
    {
        return $abilities->map(function ($ability) {
            return $ability->getAttributes();
        })->all();
    }
}
