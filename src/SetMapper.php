<?php

namespace Baum;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;

/**
 * Class SetMapper
 * @package Baum
 */
class SetMapper
{
    /**
     * Node instance for reference.
     *
     * @var Node|null
     */
    protected $node = null;

    /**
     * Children key name.
     *
     * @var string
     */
    protected $childrenKeyName = 'children';

    /**
     * Create a new \Baum\SetBuilder class instance.
     *
     * @param Node $node
     * @param string $childrenKeyName
     */
    public function __construct(Node $node, $childrenKeyName = 'children')
    {
        $this->node = $node;

        $this->childrenKeyName = $childrenKeyName;
    }

    /**
     * Maps a tree structure into the database. Unguards & wraps in transaction.
     *
     * @param array|Arrayable $nodeList
     * @return bool
     */
    public function map($nodeList)
    {
        $self = $this;

        return $this->wrapInTransaction(function() use ($self, $nodeList) {
            forward_static_call([get_class($self->node), 'unguard']);
            $result = $self->mapTree($nodeList);
            forward_static_call([get_class($self->node), 'reguard']);

            return $result;
        });
    }

    /**
     * Maps a tree structure into the database without unguarding nor wrapping
     * inside a transaction.
     *
     * @param array|Arrayable $nodeList
     *
     * @return bool
     */
    public function mapTree($nodeList)
    {
        $tree = $nodeList instanceof Arrayable ? $nodeList->toArray() : $nodeList;

        $affectedKeys = [];

        $result = $this->mapTreeRecursive($tree, $this->node->getKey(), $affectedKeys);

        if ($result && count($affectedKeys) > 0) {
            $this->deleteUnaffected($affectedKeys);
        }

        return $result;
    }

    /**
     * Returns the children key name to use on the mapping array.
     *
     * @return string
     */
    public function getChildrenKeyName()
    {
        return $this->childrenKeyName;
    }

    /**
     * Maps a tree structure into the database.
     *
     * @param array $tree
     * @param int|string|null $parentKey
     * @param array $affectedKeys
     * @return bool
     */
    protected function mapTreeRecursive(array $tree, $parentKey = null, array &$affectedKeys = [])
    {
        // For every attribute entry: We'll need to instantiate a new node either
        // from the database (if the primary key was supplied) or a new instance. Then,
        // append all the remaining data attributes (including the `parent_id` if
        // present) and save it. Finally, tail-recurse performing the same
        // operations for any child node present. Setting the `parent_id` property at
        // each level will take care of the nesting work for us.
        foreach ($tree as $attributes) {
            $node = $this->firstOrNew($this->getSearchAttributes($attributes));

            $data = $this->getDataAttributes($attributes);
            if (null !== $parentKey) {
                $data[$node->getParentColumnName()] = $parentKey;
            }

            $node->fill($data);

            $result = $node->save();

            if (!$result) {
                return false;
            }

            if (!$node->isRoot()) {
                $node->makeLastChildOf($node->parent);
            }

            $affectedKeys[] = $node->getKey();

            if (array_key_exists($this->getChildrenKeyName(), $attributes)) {
                $children = $attributes[$this->getChildrenKeyName()];

                if (count($children) > 0) {
                    $result = $this->mapTreeRecursive($children, $node->getKey(), $affectedKeys);

                    if (!$result) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param array|string $attributes
     * @return array
     */
    protected function getSearchAttributes($attributes)
    {
        $searchable = [$this->node->getKeyName()];

        return Arr::only($attributes, $searchable);
    }

    /**
     * @param array|string $attributes
     * @return array
     */
    protected function getDataAttributes($attributes)
    {
        $exceptions = [$this->node->getKeyName(), $this->getChildrenKeyName()];

        return Arr::except($attributes, $exceptions);
    }

    /**
     * @param mixed $attributes
     * @return mixed
     */
    protected function firstOrNew($attributes)
    {
        $className = get_class($this->node);

        if (count($attributes) === 0) {
            return new $className();
        }

        return forward_static_call([$className, 'firstOrNew'], $attributes);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function pruneScope()
    {
        if ($this->node->exists) {
            return $this->node->descendants();
        }

        return $this->node->newNestedSetQuery();
    }

    /**
     * @param array $keys
     * @return bool
     */
    protected function deleteUnaffected($keys = [])
    {
        return $this->pruneScope()->whereNotIn($this->node->getKeyName(), $keys)->delete();
    }

    /**
     * @param Closure $callback
     * @return mixed
     */
    protected function wrapInTransaction(Closure $callback)
    {
        return $this->node->getConnection()->transaction($callback);
    }
}
