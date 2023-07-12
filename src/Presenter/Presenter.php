<?php

declare(strict_types=1);

namespace Platim\PresenterBundle\Presenter;

use Platim\PresenterBundle\PresenterContext\DataProviderContext;
use Platim\PresenterBundle\PresenterContext\ObjectContext;

/**
 * @internal
 */
class Presenter implements PresenterInterface
{
    private ?object $object = null;
    private mixed $context = null;

    public function __construct(
        public readonly ObjectContext $objectContext,
        public readonly DataProviderContext $dataProviderContext,
    ) {
    }

    public function show(object $object, mixed $context = null): PresenterInterface
    {
        $clone = clone $this;
        $clone->object = $object;
        $clone->context = $context;

        return $clone;
    }

    public function getObject(): ?object
    {
        return $this->object;
    }

    public function getContext(): mixed
    {
        return $this->context;
    }
}
