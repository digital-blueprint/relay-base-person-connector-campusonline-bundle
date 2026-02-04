<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\EventSubscriber;

use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service\PersonProvider;
use Dbp\Relay\CoreBundle\DB\MigratePostEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

readonly class MigratePostEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            MigratePostEvent::class => 'onMigratePostEvent',
        ];
    }

    public function __construct(
        private PersonProvider $personProvider)
    {
    }

    public function onMigratePostEvent(MigratePostEvent $event): void
    {
        $output = $event->getOutput();
        try {
            // only recreate cache if it is empty (initially or after schema change)
            if (empty($this->personProvider->getPersons(1, 1))) {
                $output->writeln('Initializing base person cache...');
                $this->personProvider->recreatePersonsCache();
            }
        } catch (\Throwable $throwable) {
            $output->writeln('Error initializing base person cache: '.$throwable->getMessage());
        }
    }
}
