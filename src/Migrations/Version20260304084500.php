<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260304084500 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'creates the persons cache table';
    }

    public function up(Schema $schema): void
    {
        $this->recreateCacheTables();
    }
}
