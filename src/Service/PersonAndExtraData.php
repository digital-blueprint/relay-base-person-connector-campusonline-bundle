<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service;

use Dbp\Relay\BasePersonBundle\Entity\Person;

readonly class PersonAndExtraData
{
    public function __construct(
        private Person $person,
        private array $extraData)
    {
    }

    public function getPerson(): Person
    {
        return $this->person;
    }

    public function getExtraData(): array
    {
        return $this->extraData;
    }
}
