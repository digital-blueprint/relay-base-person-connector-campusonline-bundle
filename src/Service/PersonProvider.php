<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service;

use Dbp\Relay\BasePersonBundle\API\PersonProviderInterface;
use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\CoreBundle\LocalData\LocalDataEventDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class PersonProvider implements PersonProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private LocalDataEventDispatcher $eventDispatcher;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = new LocalDataEventDispatcher('', $eventDispatcher);
    }

    public function setConfig(array $config): void
    {
    }

    public function checkConnection()
    {
    }

    public function recreatePersonsCache(): void
    {
    }

    public function getPerson(string $identifier, array $options = []): Person
    {
        throw new \RuntimeException('Not implemented');
    }

    public function getPersons(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        return [];
    }

    public function getCurrentPersonIdentifier(): ?string
    {
        return null;
    }

    public function getCurrentPerson(array $options = []): ?Person
    {
        return null;
    }
}
