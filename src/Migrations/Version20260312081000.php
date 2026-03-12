<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260312081000 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 're-creates the persons cache table (new column titleSuffix)';
    }

    public function up(Schema $schema): void
    {
        $this->recreateCacheTables();
    }
}
