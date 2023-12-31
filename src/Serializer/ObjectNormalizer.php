<?php

declare(strict_types=1);

namespace Platim\PresenterBundle\Serializer;

use Platim\Presenter\Contracts\Metadata\MetadataRegistryInterface;
use Platim\PresenterBundle\NameConverter\NameConverterRegistry;
use Platim\PresenterBundle\Presenter\Presenter;
use Platim\PresenterBundle\PresenterContext\ObjectContext;
use Platim\PresenterBundle\PresenterContext\ObjectContextFactory;
use Platim\PresenterBundle\PresenterHandler\PresenterHandlerRegistry;
use Platim\PresenterBundle\Request\Expand\ExpandRequest;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyInfo\PropertyListExtractorInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

class ObjectNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    private NormalizerInterface|SerializerInterface $normalizer;
    private NameConverterInterface $nameConverter;

    public function __construct(
        private readonly PresenterHandlerRegistry $presenterHandlerRegistry,
        private readonly NameConverterRegistry $nameConverterRegistry,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly PropertyListExtractorInterface $propertyListExtractor,
        private readonly MetadataRegistryInterface $metadataRegistry,
        private readonly ObjectContextFactory $objectContextFactory,
        NameConverterInterface $nameConverter = null
    ) {
        $this->nameConverter = $nameConverter ?? new DummyNameConverter();
    }

    public function normalize($object, string $format = null, array $context = []): \ArrayObject|array
    {
        if ($object instanceof Presenter) {
            $objectContext = $object->objectContext;
            $presenterContext = $object->getContext();
            $object = $object->getObject();
        } else {
            $objectContext = $this->objectContextFactory->createFromArrayContext($context);
            $presenterContext = null;
        }

        if (null === $objectContext->nameConverter) {
            $objectContext->nameConverter = $this->nameConverterRegistry->getNameConverter($objectContext->group);
        }

        $result = $this->expand(
            $object,
            $objectContext,
            $format,
            $presenterContext,
        );

        return \count($result) ? $result : new \ArrayObject();
    }

    public function supportsNormalization($data, string $format = null, array $context = []): bool
    {
        if (\is_object($data)) {
            $class = $this->getObjectClass($data);

            return $this->presenterHandlerRegistry->hasPresenterHandlerForClass($class)
                || null !== $this->metadataRegistry->getMetadataForClass($class);
        }

        return false;
    }

    public function expand(
        object $object,
        ObjectContext $objectContext,
        string $format = null,
        mixed $presenterContext = null,
    ): array {
        $data = [];

        $class = $this->getObjectClass($object);

        $nameConverter = $objectContext->nameConverter ?? $this->nameConverter;
        $group = $objectContext->group;
        $contextArray = $objectContext->toArray();
        $expand = $objectContext->expandRequest?->getExpand() ?? [];

        [$presenterHandler, $method] = $this->presenterHandlerRegistry->getPresenterHandlerForClass($class, $group);
        $metaData = $this->metadataRegistry->getMetadataForClass($class);

        if (\is_callable([$presenterHandler, $method])) {
            $presented = $presenterHandler->$method($object, $presenterContext);
        } elseif (null !== $metaData) {
            $presented = [];
            foreach ($metaData->getFieldNames() as $fieldName) {
                if ($this->propertyAccessor->isReadable($object, $fieldName)) {
                    $presented[$fieldName] = $this->propertyAccessor->getValue($object, $fieldName);
                }
            }
        } else {
            $presented = $object;
        }

        if (\is_object($presented) && $class !== $presented::class) {
            $presented = $this->normalizer->normalize($presented, $format, $contextArray);
        }

        if (\is_object($presented)) {
            foreach ($this->propertyListExtractor->getProperties($presented::class) as $property) {
                if ($this->propertyAccessor->isReadable($presented, $property)) {
                    $data[$property] = $this->propertyAccessor->getValue($presented, $property);
                }
            }
        } elseif (\is_array($presented)) {
            $data = $presented;
        }

        $result = [];
        foreach ($data as $name => $value) {
            $result[$nameConverter->normalize($name)] = $value;
        }

        $metaData = $this->metadataRegistry->getMetadataForClass($class);
        $customExpandFields = $this->presenterHandlerRegistry->getCustomExpandFieldsForClass($class, $group);

        $expandable = [];
        if (null !== $customExpandFields) {
            $expandable = $customExpandFields->getExpandFields();
        } elseif (null !== $metaData) {
            $expandable = $metaData->getAssociationNames();
        }
        if (!\count($expandable)) {
            return $result;
        }
        $expandableNormalized = [];
        foreach ($expandable as $key => $value) {
            if (\is_string($key) && (\is_string($value) || \is_callable($value))) {
                $expandableNormalized[$nameConverter->normalize($key)] = $value;
            } elseif (\is_int($key) && \is_string($value)) {
                $expandableNormalized[$nameConverter->normalize($value)] = $value;
            }
        }
        $expandTree = [];
        if (\in_array('*', $expand, true)) {
            foreach ($expandableNormalized as $key => $value) {
                $expandTree[$key] = [];
            }
        }
        $expand = array_filter($expand, static fn ($item) => !str_contains($item, '*'));
        foreach ($expand as $expandItem) {
            $normalizedNames = array_map(
                static fn ($item) => $nameConverter->normalize($item),
                explode('.', $expandItem)
            );
            $normalizedName = array_shift($normalizedNames);
            if (!\array_key_exists($normalizedName, $expandableNormalized)) {
                continue;
            }
            if (\count($normalizedNames)) {
                $nestedExpand = implode('.', $normalizedNames);
                $expandTree[$normalizedName][$nestedExpand] = $nestedExpand;
            } else {
                $expandTree[$normalizedName] = [];
            }
        }

        foreach ($expandTree as $expandName => $nestedExpand) {
            $expandContext = clone $objectContext;
            $expandContext->expandRequest = new ExpandRequest(array_values($nestedExpand));
            if (\array_key_exists($expandName, $expandableNormalized)) {
                $expandableField = $expandableNormalized[$expandName];
                if (\is_string($expandableField)) {
                    if ($this->propertyAccessor->isReadable($object, $expandableField)) {
                        $value = $this->propertyAccessor->getValue($object, $expandableField);
                        if (null !== $metaData && $metaData->hasAssociation($expandableField)) {
                            $multiple = $metaData->isAssociationMultiple($expandableField);
                            if (null === $value) {
                                $result[$expandName] = null;
                            } elseif ($multiple) {
                                $value = $metaData->getAssociationValueAsArray($value);
                                $result[$expandName] = array_map(
                                    fn ($association) => $this->expand($association, $expandContext, $format, $presenterContext),
                                    $value
                                );
                            } else {
                                $result[$expandName] = $this->expand($value, $expandContext, $format, $presenterContext);
                            }
                        } else {
                            $result[$expandName] = $this->normalizer->normalize($value, $format, $expandContext->toArray());
                        }
                    }
                } elseif (\is_callable($expandableField)) {
                    $value = $expandableField($object, $presenterContext);
                    if (\is_object($value)) {
                        if ($value instanceof \ArrayAccess) {
                            $result[$expandName] = array_map(
                                fn ($association) => $this->expand($association, $expandContext, $format, $presenterContext),
                                (array) $value
                            );
                        } else {
                            $result[$expandName] = $this->expand($value, $expandContext, $format, $presenterContext);
                        }
                    } else {
                        $result[$expandName] = $this->normalizer->normalize($value, $format, $expandContext->toArray());
                    }
                }
            }
        }

        return $result;
    }

    public function setSerializer(SerializerInterface $serializer): void
    {
        $this->normalizer = $serializer;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            'object' => true,
        ];
    }

    private function getObjectClass(object $object): string
    {
        if ($object instanceof Presenter) {
            $object = $object->getObject();
        }

        return $this->metadataRegistry->getObjectClass($object);
    }
}
