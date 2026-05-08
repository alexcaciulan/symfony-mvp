<?php

namespace App\Workflow\MarkingStore;

use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

class EnumMarkingStore implements MarkingStoreInterface
{
    /**
     * @param class-string<\BackedEnum> $enumClass
     */
    public function __construct(
        private string $property,
        private string $enumClass,
    ) {}

    public function getMarking(object $subject): Marking
    {
        $getter = 'get' . ucfirst($this->property);
        $value = $subject->{$getter}();

        if ($value === null) {
            return new Marking();
        }

        if (!$value instanceof \BackedEnum) {
            throw new \LogicException(sprintf(
                '%s::%s() must return a BackedEnum instance, got %s.',
                $subject::class,
                $getter,
                get_debug_type($value),
            ));
        }

        return new Marking([$value->value => 1]);
    }

    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $places = $marking->getPlaces();
        $name = (string) key($places);
        $setter = 'set' . ucfirst($this->property);
        $subject->{$setter}(($this->enumClass)::from($name));
    }
}
