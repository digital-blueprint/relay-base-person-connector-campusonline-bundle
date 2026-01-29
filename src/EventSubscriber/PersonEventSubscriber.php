<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\EventSubscriber;

use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Event\PersonPostEvent;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Event\PersonPreEvent;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service\PersonProvider;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataEventSubscriber;
use Dbp\Relay\CoreBundle\LocalData\LocalDataPostEvent;

class PersonEventSubscriber extends AbstractLocalDataEventSubscriber
{
    protected static function getSubscribedEventNames(): array
    {
        return [
            PersonPreEvent::class,
            PersonPostEvent::class,
        ];
    }

    public function __construct(private readonly PersonProvider $personProvider)
    {
        parent::__construct('BasePerson');
    }

    protected function getAttributeValue(LocalDataPostEvent $postEvent, array $attributeMapEntry): mixed
    {
        $course = $postEvent->getEntity();
        assert($course instanceof Person);

        //        switch ($attributeMapEntry[self::SOURCE_ATTRIBUTE_KEY]) {
        //        }

        return parent::getAttributeValue($postEvent, $attributeMapEntry);
    }
}
