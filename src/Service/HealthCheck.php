<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service;

use Dbp\Relay\CoreBundle\HealthCheck\CheckInterface;
use Dbp\Relay\CoreBundle\HealthCheck\CheckOptions;
use Dbp\Relay\CoreBundle\HealthCheck\CheckResult;

readonly class HealthCheck implements CheckInterface
{
    public function __construct(private PersonProvider $personProvider)
    {
    }

    public function getName(): string
    {
        return 'base-person-connector-campusonline';
    }

    public function check(CheckOptions $options): array
    {
        $result = new CheckResult('Check if the CO REST person-claims API works');

        $result->set(CheckResult::STATUS_SUCCESS);
        try {
            $this->personProvider->checkConnection();
        } catch (\Throwable $e) {
            $result->set(CheckResult::STATUS_FAILURE, $e->getMessage(), ['exception' => $e]);
        }

        return [$result];
    }
}
