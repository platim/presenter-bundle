<?php

declare(strict_types=1);

namespace Platim\PresenterBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class NameConverter
{
    public function __construct(
        public ?string $group = null,
    ) {
    }
}
