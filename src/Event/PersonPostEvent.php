<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Event;

use Dbp\Relay\BasePersonBundle\API\PersonProviderInterface;
use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\CoreBundle\LocalData\LocalDataPostEvent;

class PersonPostEvent extends LocalDataPostEvent
{
    public function __construct(Person $entity, array $sourceData,
        private readonly PersonProviderInterface $personProvider,
        array $options = [])
    {
        parent::__construct($entity, $sourceData, $options);
    }

    public function getPersonProvider(): PersonProviderInterface
    {
        return $this->personProvider;
    }
}
