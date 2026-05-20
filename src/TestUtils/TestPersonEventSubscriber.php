<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\TestUtils;

use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Event\RecreatePersonCachePostEvent;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service\PersonProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

readonly class TestPersonEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RecreatePersonCachePostEvent::class => 'onRecreatePersonCachePostEvent',
        ];
    }

    public function __construct(private PersonProvider $personProvider)
    {
    }

    /**
     * @throws \Throwable
     */
    public function onRecreatePersonCachePostEvent(RecreatePersonCachePostEvent $event): void
    {
        $this->personProvider->addPersonsToStagingTable([TestPersonProvider::EXTERNAL_USER_IDENTIFIER], areAllExternals: true);
    }
}
