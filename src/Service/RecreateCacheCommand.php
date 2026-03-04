<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'dbp:relay:base-person-connector-campusonline:recreate-cache',
    description: 'Re-create the person cache',
)]
class RecreateCacheCommand extends Command
{
    public function __construct(private readonly PersonProvider $personProvider)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->personProvider->recreatePersonsCache();

        return Command::SUCCESS;
    }
}
