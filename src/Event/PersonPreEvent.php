<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Event;

use Dbp\Relay\BasePersonBundle\API\PersonProviderInterface;
use Dbp\Relay\CoreBundle\LocalData\LocalDataPreEvent;

class PersonPreEvent extends LocalDataPreEvent
{
    public function __construct(array $options,
        private ?string $identifier,
        private readonly PersonProviderInterface $personProvider)
    {
        parent::__construct($options);
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(?string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function getPersonProvider(): PersonProviderInterface
    {
        return $this->personProvider;
    }
}
