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
        $results = [];

        $result = new CheckResult('Check if the CO REST person-claims API works');
        $result->set(CheckResult::STATUS_SUCCESS);
        try {
            $this->personProvider->checkPersonClaimsApi();
        } catch (\Throwable $e) {
            $result->set(CheckResult::STATUS_FAILURE, $e->getMessage(), ['exception' => $e]);
        }
        $results[] = $result;

        $result = new CheckResult('Check if the CO REST users API works');
        $result->set(CheckResult::STATUS_SUCCESS);
        try {
            $this->personProvider->checkUsersApi();
        } catch (\Throwable $e) {
            $result->set(CheckResult::STATUS_FAILURE, $e->getMessage(), ['exception' => $e]);
        }
        $results[] = $result;

        return $results;
    }
}
