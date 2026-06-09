<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service;

use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPerson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'dbp:relay:base-person-connector-campusonline:show-person',
    description: 'Show all cached information for a specific person ID',
)]
class ShowPersonCommand extends Command
{
    private const ACCOUNT_TYPE_KEYS = [
        CachedPerson::IS_STAFF,
        CachedPerson::IS_STUDENT,
        CachedPerson::IS_ALUMNI,
        CachedPerson::IS_EXTERNAL,
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('identifier', InputArgument::REQUIRED, 'The person UID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = $input->getArgument('identifier');

        $cachedPerson = $this->entityManager->getRepository(CachedPerson::class)->find($identifier);

        if ($cachedPerson === null) {
            $io->error(sprintf("Person '%s' not found in cache.", $identifier));

            return Command::FAILURE;
        }

        $io->title(sprintf('Person: %s', $identifier));

        // uid + givenName + familyName (from BASE_ENTITY_ATTRIBUTE_MAPPING)
        $rows = [
            [CachedPerson::UID, $cachedPerson->getUid() ?? ''],
            [CachedPerson::GIVEN_NAME, $cachedPerson->getGivenName() ?? ''],
            ['familyName', $cachedPerson->getSurname() ?? ''],
        ];

        // all remaining fields from LOCAL_DATA_SOURCE_ATTRIBUTES (dateOfBirth, email, …, isStaff, …)
        foreach (CachedPerson::LOCAL_DATA_SOURCE_ATTRIBUTES as $key => $getter) {
            $value = $cachedPerson->$getter();
            if (in_array($key, self::ACCOUNT_TYPE_KEYS, true)) {
                $value = match ($value) {
                    CachedPerson::YES_WITH_ACCOUNT => 'yes (with account)',
                    CachedPerson::YES_WITHOUT_ACCOUNT => 'yes (no account)',
                    default => 'no',
                };
            }
            $rows[] = [$key, (string) ($value ?? '')];
        }

        $io->table(['Key', 'Value'], $rows);

        return Command::SUCCESS;
    }
}
