<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service;

use Dbp\CampusonlineApi\Helpers\ApiException;
use Dbp\CampusonlineApi\PublicRestApi\Accounts\Common as AccountsCommon;
use Dbp\CampusonlineApi\PublicRestApi\Accounts\UserApi;
use Dbp\CampusonlineApi\PublicRestApi\Accounts\UserResource;
use Dbp\CampusonlineApi\PublicRestApi\Connection;
use Dbp\CampusonlineApi\PublicRestApi\Organizations\PersonOrganisationApi;
use Dbp\CampusonlineApi\PublicRestApi\Organizations\PersonOrganisationResource;
use Dbp\CampusonlineApi\PublicRestApi\Persons\Common;
use Dbp\CampusonlineApi\PublicRestApi\Persons\PersonClaimsApi;
use Dbp\CampusonlineApi\PublicRestApi\Persons\PersonClaimsResource;
use Dbp\Relay\BasePersonBundle\API\PersonProviderInterface;
use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\DependencyInjection\Configuration;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPerson;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPersonStaging;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Event\PersonPostEvent;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Event\PersonPreEvent;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\EventSubscriber\PersonEventSubscriber;
use Dbp\Relay\CoreBundle\Doctrine\QueryHelper;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\LocalData\LocalDataEventDispatcher;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterException;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTools;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Dbp\Relay\CoreBundle\Rest\Query\Pagination\Pagination;
use Dbp\Relay\CoreBundle\Rest\Query\Sort\Sort;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

class PersonProvider implements PersonProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const PERSON_CLAIMS_TO_FETCH = [
        Common::NAME_CLAIM,
        Common::DATE_OF_BIRTH_CLAIM,
        Common::EMAIL_CLAIM,
        Common::EMAIL_EMPLOYEE_CLAIM,
        Common::EMAIL_STUDENT_CLAIM,
        Common::EMAIL_EXTPERS_CLAIM,
        Common::MATRICULATION_NUMBER_CLAIM,
        Common::GENDER_CLAIM,
        Common::TITLE_CLAIM,
        Common::PERSON_GROUPS_CLAIM,
    ];
    private const MAX_NUM_PERSON_UIDS_PER_REQUEST = 50;

    private ?PersonClaimsApi $personClaimsApi = null;
    private LocalDataEventDispatcher $eventDispatcher;

    /**
     * @var array<string, mixed>
     */
    private array $config = [];

    /**
     * @var array<string, PersonClaimsResource>
     */
    private array $personClaimsRequestCache = [];
    /**
     * @var string[]
     */
    private array $currentResultPersonUids = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = new LocalDataEventDispatcher('', $eventDispatcher);
    }

    public function setConfig(array $config): void
    {
        $this->config = $config[Configuration::CAMPUS_ONLINE_NODE];
    }

    public function checkConnection(): void
    {
        $this->getPersonClaimsApi()->getPersonClaimsPageOffsetBased();
    }

    /**
     * @throws \Throwable
     */
    public function recreatePersonsCache(): void
    {
        $connection = $this->entityManager->getConnection();
        try {
            // TODO: make non-const account type keys configurable via config instead of hardcoding them
            $userApi = new UserApi($this->getConnection());
            $userApiQueryParameters = [
                UserApi::ACCOUNT_STATUS_KEY_QUERY_PARAMETER_NAME => AccountsCommon::OK_ACCOUNT_STATUS_KEY,
                UserApi::ACCOUNT_TYPE_KEY_QUERY_PARAMETER_NAME => ['STAFF', 'STUDENT'],
            ];
            $nextUsersCursor = null;
            do {
                $userResourcePage = $userApi->getUsersCursorBased(
                    queryParameters: $userApiQueryParameters,
                    cursor: $nextUsersCursor,
                    maxNumItems: 1000);

                $personsToFetch = [];
                /** @var UserResource $userResource */
                foreach ($userResourcePage->getResources() as $userResource) {
                    $personGroupKeys = 0;
                    for ($accountIndex = 0; $accountIndex < $userResource->getNumAccounts(); ++$accountIndex) {
                        if ($userResource->getAccountStatusKey($accountIndex) === AccountsCommon::OK_ACCOUNT_STATUS_KEY) {
                            $personGroupKeys |= match ($userResource->getAccountTypeKey($accountIndex)) {
                                'STAFF' => CachedPerson::EMPLOYEE_PERSON_GROUP_MASK,
                                'STUDENT' => CachedPerson::STUDENT_PERSON_GROUP_MASK,
                                default => 0,
                            };
                        }
                    }
                    if ($personGroupKeys !== 0) {
                        $personsToFetch[$userResource->getPersonUid()] = $personGroupKeys;
                    }
                }

                $currentPersonIndex = 0;
                while ($currentPersonIndex < count($personsToFetch)) {
                    $personClaimsQueryParameters = [
                        PersonClaimsApi::PERSON_UID_QUERY_PARAMETER_NAME => array_slice(
                            array_keys($personsToFetch),
                            $currentPersonIndex,
                            self::MAX_NUM_PERSON_UIDS_PER_REQUEST),
                    ];

                    foreach ($this->getPersonClaimsApi()->getPersonClaimsPageOffsetBased(
                        queryParameters: $personClaimsQueryParameters,
                        claims: self::PERSON_CLAIMS_TO_FETCH,
                        maxNumItems: self::MAX_NUM_PERSON_UIDS_PER_REQUEST) as $personClaimsResource) {
                        $cachedPersonStaging = self::createCachedPersonStagingFromPersonClaimsResource(
                            $personClaimsResource);
                        $cachedPersonStaging->setPersonGroups(
                            $personsToFetch[$personClaimsResource->getUid()]);
                        $this->entityManager->persist($cachedPersonStaging);
                    }
                    $currentPersonIndex += self::MAX_NUM_PERSON_UIDS_PER_REQUEST;
                }

                $this->entityManager->flush();
                $this->entityManager->clear();
            } while (($nextUsersCursor = $userResourcePage->getNextCursor()) !== null);

            $personsStagingTableName = CachedPersonStaging::TABLE_NAME;
            $personsLiveTableName = CachedPerson::TABLE_NAME;
            $personsTempTableName = $personsLiveTableName.'_temp';

            // swap live and staging tables:
            $connection->executeStatement(<<<STMT
                RENAME TABLE
                $personsLiveTableName TO $personsTempTableName,
                $personsStagingTableName TO $personsLiveTableName,
                $personsTempTableName TO $personsStagingTableName
                STMT);
        } catch (\Throwable $throwable) {
            $this->logger?->error('Error recreating person cache: '.$throwable->getMessage());
            throw $throwable;
        } finally {
            $connection->executeStatement('TRUNCATE TABLE '.CachedPersonStaging::TABLE_NAME);
        }
    }

    public function getPerson(string $identifier, array $options = []): Person
    {
        $this->eventDispatcher->onNewOperation($options);

        $preEvent = new PersonPreEvent($options, $identifier, $this);
        $this->eventDispatcher->dispatch($preEvent);

        $identifier = $preEvent->getIdentifier();
        $options = $preEvent->getOptions();

        try {
            $cachedPerson = $this->entityManager->getRepository(CachedPerson::class)->find($identifier);
            if ($cachedPerson === null) {
                throw new ApiError(Response::HTTP_NOT_FOUND, 'person with ID not found: '.$identifier);
            }
        } catch (ApiException $apiException) {
            throw self::dispatchException($apiException, $identifier);
        }

        $this->currentResultPersonUids = [$cachedPerson->getUid()];

        return $this->postProcessPerson(
            self::createPersonAndExtraDataFromCachedPerson($cachedPerson, $options), $options);
    }

    public function getPersons(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $this->eventDispatcher->onNewOperation($options);

        //        Options::setSort($options, new Sort([
        //            Sort::createSortField('familyName', Sort::ASCENDING_DIRECTION),
        //            Sort::createSortField('givenName', Sort::ASCENDING_DIRECTION),
        //        ]));

        $preEvent = new PersonPreEvent($options, null, $this);
        $this->eventDispatcher->dispatch($preEvent);
        $options = $preEvent->getOptions();

        try {
            $persons = [];
            foreach ($this->getPersonsFromCache(
                Pagination::getFirstItemIndex($currentPageNumber, $maxNumItemsPerPage),
                $maxNumItemsPerPage, $options) as $personAndExtraData) {
                $persons[] = $this->postProcessPerson(
                    $personAndExtraData,
                    $options);
            }

            return $persons;
        } catch (ApiException $apiException) {
            throw self::dispatchException($apiException);
        }
    }

    public function getCurrentPersonIdentifier(): ?string
    {
        return null;
    }

    public function getCurrentPerson(array $options = []): ?Person
    {
        return null;
    }

    /**
     * @return string[]
     */
    public function getPersonIdentifiersByOrganization(string $organizationIdentifier): array
    {
        // NOTE: the CO person-organisations operation needs deduplication.
        $personFunctionsApi = new PersonOrganisationApi($this->getConnection());
        $nextCursor = null;
        $personIdentifiers = [];
        do {
            $resourcePage = $personFunctionsApi->getPersonOrganisationsCursorBased(
                queryParameters: [
                    PersonOrganisationApi::ORGANISATION_UID_QUERY_PARAMETER_NAME => $organizationIdentifier,
                ],
                cursor: $nextCursor,
                maxNumItems: 1000);

            /** @var PersonOrganisationResource $personOrganisationResource */
            foreach ($resourcePage->getResources() as $personOrganisationResource) {
                $personIdentifiers[$personOrganisationResource->getPersonUid()] = null; // deduplicate via array keys
            }
        } while (($nextCursor = $resourcePage->getNextCursor()) !== null);

        return array_keys($personIdentifiers);
    }

    /**
     * @return string[]|null
     */
    public function getOrganizationIdentifiersByPerson(?string $personIdentifier): ?array
    {
        return null;
    }

    private function getPersonClaimsApi(): PersonClaimsApi
    {
        if ($this->personClaimsApi === null) {
            $this->personClaimsApi = new PersonClaimsApi(
                new Connection(
                    $this->config['base_url'],
                    $this->config['client_id'],
                    $this->config['client_secret']
                )
            );
        }

        return $this->personClaimsApi;
    }

    private function getConnection(): Connection
    {
        return $this->getPersonClaimsApi()->getConnection();
    }

    /**
     * @return iterable<PersonAndExtraData>
     */
    private function getPersonsFromCache(int $firstItemIndex, int $maxNumItems, array $options = []): iterable
    {
        $CACHED_PERSON_ENTITY_ALIAS = 'p';

        $combinedFilter = null;
        if ($searchOption = $options[Person::SEARCH_PARAMETER_NAME] ?? null) {
            try {
                // the full name MUST contain ALL search terms
                $filterTreeBuilder = FilterTreeBuilder::create();
                foreach (explode(' ', $searchOption) as $searchTerm) {
                    $searchTerm = trim($searchTerm);
                    $filterTreeBuilder
                        ->or()
                        ->iContains('givenName', $searchTerm)
                        ->iContains('familyName', $searchTerm)
                        ->end();
                }
                $combinedFilter = $filterTreeBuilder->createFilter();
            } catch (FilterException $filterException) {
                $this->logger->error('failed to build filter for person search: '.$filterException->getMessage(), [$filterException]);
                throw new \RuntimeException('failed to build filter for person search');
            }
        }

        if ($filter = Options::getFilter($options)) {
            try {
                $combinedFilter = $combinedFilter ?
                    $combinedFilter->combineWith($filter) : $filter;
            } catch (FilterException $filterException) {
                $this->logger->error('failed to combine filters for persons query: '.$filterException->getMessage(), [$filterException]);
                throw new \RuntimeException('failed to combine filters for persons query');
            }
        }

        $sort = Options::getSort($options);

        $pathMapping = [];
        if ($combinedFilter !== null || $sort !== null) {
            $pathMapping = array_map(
                function ($columnName) use ($CACHED_PERSON_ENTITY_ALIAS) {
                    return $CACHED_PERSON_ENTITY_ALIAS.'.'.$columnName;
                }, CachedPerson::BASE_ENTITY_ATTRIBUTE_MAPPING);

            $localDataSourceAttributes = array_merge(
                array_keys(CachedPerson::LOCAL_DATA_SOURCE_ATTRIBUTES),
                PersonEventSubscriber::LOCAL_DATA_SOURCE_ATTRIBUTES
            );
            foreach ($localDataSourceAttributes as $localDataSourceAttribute) {
                $pathMapping[$localDataSourceAttribute] = $CACHED_PERSON_ENTITY_ALIAS.'.'.$localDataSourceAttribute;
            }
        }

        $queryBuilder = $this->entityManager->createQueryBuilder();
        $queryBuilder
            ->select($CACHED_PERSON_ENTITY_ALIAS)
            ->from(CachedPerson::class, $CACHED_PERSON_ENTITY_ALIAS);

        if ($combinedFilter !== null) {
            FilterTools::mapConditionPaths($combinedFilter, $pathMapping);
            try {
                QueryHelper::addFilter($queryBuilder, $combinedFilter);
            } catch (\Exception $exception) {
                $this->logger->error('failed to apply filter to course query: '.$exception->getMessage(), [$exception]);
                throw new \RuntimeException('failed to apply filter to course query');
            }
        }

        if ($sort !== null) {
            foreach ($sort->getSortFields() as $sortField) {
                if ($column = $pathMapping[Sort::getPath($sortField)] ?? null) {
                    $queryBuilder->addOrderBy($column, Sort::getDirection($sortField) === Sort::DESCENDING_DIRECTION ? 'DESC' : 'ASC');
                } else {
                    throw new \RuntimeException('unable to sort by unknown attribute: '.Sort::getPath($sortField));
                }
            }
        }

        $paginator = new Paginator($queryBuilder->getQuery());
        $paginator->getQuery()
            ->setFirstResult($firstItemIndex)
            ->setMaxResults($maxNumItems);

        $cachedPersons = [];
        /** @var CachedPerson $cachedPerson */
        foreach ($paginator as $cachedPerson) {
            $cachedPersons[] = $cachedPerson;
        }

        $this->currentResultPersonUids = [];
        foreach ($cachedPersons as $cachedPerson) {
            $this->currentResultPersonUids[] = $cachedPerson->getUid();
            yield self::createPersonAndExtraDataFromCachedPerson($cachedPerson, $options);
        }
    }

    /**
     * Gets all persons of the current result set from the API and caches them locally, so that not every person
     * has to be requested individually on setting local data attributes.
     */
    public function getAndCacheCurrentResultPersonsFromApi(): void
    {
        $currentPersonIndex = 0;
        while ($currentPersonIndex < count($this->currentResultPersonUids)) {
            $queryParameters = [
                PersonClaimsApi::PERSON_UID_QUERY_PARAMETER_NAME => array_slice(
                    $this->currentResultPersonUids,
                    $currentPersonIndex,
                    self::MAX_NUM_PERSON_UIDS_PER_REQUEST),
            ];

            try {
                $resourcePage = $this->getPersonClaimsApi()->getPersonClaimsPageCursorBased(
                    queryParameters: $queryParameters,
                    claims: [Common::ALL_CLAIM],
                    maxNumItems: self::MAX_NUM_PERSON_UIDS_PER_REQUEST);
            } catch (ApiException $apiException) {
                if (false === $apiException->isHttpResponseCode()
                    || $apiException->getCode() !== Response::HTTP_INTERNAL_SERVER_ERROR) {
                    throw self::dispatchException($apiException);
                }
                // else ignore known CO bug that returns HTTP 500 (NullPointerException) for some records when addresses are requested
                $resourcePage = $this->getPersonClaimsApi()->getPersonClaimsPageCursorBased(
                    queryParameters: $queryParameters,
                    claims: self::PERSON_CLAIMS_TO_FETCH, // those are safe to fetch
                    maxNumItems: self::MAX_NUM_PERSON_UIDS_PER_REQUEST);
            }

            /** @var PersonClaimsResource $personClaimsResource */
            foreach ($resourcePage->getResources() as $personClaimsResource) {
                dump($personClaimsResource);
                $this->personClaimsRequestCache[$personClaimsResource->getUid()] = $personClaimsResource;
            }
            $currentPersonIndex += self::MAX_NUM_PERSON_UIDS_PER_REQUEST;
        }
        $this->currentResultPersonUids = []; // make sure we don't cache them again
    }

    /**
     * @return array<string, string>|null
     */
    public function getEmployeePostalAddress(?string $personIdentifier): ?array
    {
        return $this->getEmployeeAddress($personIdentifier, 'PA');
    }

    /**
     * @return array<string, string>|null
     */
    public function getEmployeeWorkAddress(string $personIdentifier): ?array
    {
        return $this->getEmployeeAddress($personIdentifier, 'DO');
    }

    /**
     * @return array<string, string>|null
     */
    public function getEmployeeAddress(string $personIdentifier, string $employeeAdressTypeAbbreviation): ?array
    {
        $address = null;
        $personClaims = $this->getPersonFromApiCached($personIdentifier);
        for ($addressIndex = 0; $addressIndex < $personClaims->getNumAddresses(); ++$addressIndex) {
            if ($personClaims->getEmployeeAddressTypeAbbreviation($addressIndex) === $employeeAdressTypeAbbreviation) {
                $address = [
                    'street' => $personClaims->getAddressStreet($addressIndex),
                    'postalCode' => $personClaims->getAddressPostalCode($addressIndex),
                    'city' => $personClaims->getAddressCity($addressIndex),
                    'country' => $personClaims->getAddressCountry($addressIndex),
                ];
                break;
            }
        }

        return $address;
    }

    public function getPersonFromApiCached(string $personIdentifier): PersonClaimsResource
    {
        if (($personClaimsCached = $this->personClaimsRequestCache[$personIdentifier] ?? null) === null) {
            try {
                $personClaimsCached = $this->getPersonClaimsApi()->getPersonClaimsByPersonUid($personIdentifier, [
                    Common::ALL_CLAIM,
                ]);
            } catch (ApiException $apiException) {
                if (false === $apiException->isHttpResponseCode()
                    || $apiException->getCode() !== Response::HTTP_INTERNAL_SERVER_ERROR) {
                    throw self::dispatchException($apiException, $personIdentifier);
                }
                // else ignore known CO bug that returns HTTP 500 (NullPointerException) for some records when addresses are requested
                $personClaimsCached = $this->getPersonClaimsApi()->getPersonClaimsByPersonUid($personIdentifier, [
                    self::PERSON_CLAIMS_TO_FETCH, // those are safe to fetch
                ]);
            }
            $this->personClaimsRequestCache[$personIdentifier] = $personClaimsCached;
        }

        return $personClaimsCached;
    }

    private function postProcessPerson(PersonAndExtraData $personAndExtraData, array $options): Person
    {
        $postEvent = new PersonPostEvent(
            $personAndExtraData->getPerson(), $personAndExtraData->getExtraData(), $this, $options);
        $this->eventDispatcher->dispatch($postEvent);

        return $personAndExtraData->getPerson();
    }

    private static function createPersonAndExtraDataFromPersonClaimsResource(
        PersonClaimsResource $personClaimsResource, array $options): PersonAndExtraData
    {
        $person = new Person();
        $person->setIdentifier($personClaimsResource->getUid());
        $person->setGivenName($personClaimsResource->getGivenName());
        $person->setFamilyName($personClaimsResource->getSurname());

        return new PersonAndExtraData($person, $personClaimsResource->getResourceData());
    }

    private static function createPersonAndExtraDataFromCachedPerson(
        CachedPerson $cachedPerson, array $options): PersonAndExtraData
    {
        $person = new Person();
        $person->setIdentifier($cachedPerson->getUid());
        $person->setGivenName($cachedPerson->getGivenName());
        $person->setFamilyName($cachedPerson->getSurname());

        return new PersonAndExtraData($person, $cachedPerson->getLocalDataSourceAttributeValues());
    }

    private static function createCachedPersonStagingFromPersonClaimsResource(
        PersonClaimsResource $personClaimsResource): CachedPersonStaging
    {
        $cachedPerson = new CachedPersonStaging();
        $cachedPerson->setUid($personClaimsResource->getUid());
        $cachedPerson->setGivenName($personClaimsResource->getGivenName());
        $cachedPerson->setSurname($personClaimsResource->getSurname());
        try {
            $cachedPerson->setDateOfBirth(($dateOfBirth = $personClaimsResource->getDateOfBirth()) ?
                new \DateTime($dateOfBirth) : null);
        } catch (\Exception) {
        }
        $cachedPerson->setEmail(self::getMainEmail($personClaimsResource));
        $cachedPerson->setMatriculationNumber($personClaimsResource->getMatriculationNumber());
        $cachedPerson->setGenderKey($personClaimsResource->getGenderKey());
        $cachedPerson->setTitlePrefix($personClaimsResource->getTitlePrefix());
        if ($personGroups = $personClaimsResource->getPersonGroups()) {
            if (in_array(PersonClaimsApi::STUDENT_PERSON_GROUP_KEY, $personGroups, true)) {
                $cachedPerson->makeStudent();
            } elseif (in_array(PersonClaimsApi::EMPLOYEE_PERSON_GROUP_KEY, $personGroups, true)) {
                $cachedPerson->makeEmployee();
            } elseif (in_array(PersonClaimsApi::EXTERNAL_PERSON_GROUP_KEY, $personGroups, true)) {
                $cachedPerson->makeExternalPerson();
            }
        }

        return $cachedPerson;
    }

    private static function getMainEmail(PersonClaimsResource $personClaimsResource): ?string
    {
        if ($email = $personClaimsResource->getEmail()) {
            return $email;
        }
        if ($email = $personClaimsResource->getEmailEmployee()) {
            return $email;
        }
        if ($email = $personClaimsResource->getEmailStudent()) {
            return $email;
        }
        if ($email = $personClaimsResource->getEmailExtpers()) {
            return $email;
        }

        return null;
    }

    /**
     * NOTE: Campusonline returns '401 unauthorized' for some resources that are not found. So we can't
     * safely return '404' in all cases because '401' is also returned by CO if e.g. the token is not valid.
     *
     * @throws ApiError
     */
    private static function dispatchException(ApiException $apiException, ?string $identifier = null): ApiError
    {
        if ($apiException->isHttpResponseCode()) {
            switch ($apiException->getCode()) {
                case Response::HTTP_NOT_FOUND:
                    if ($identifier !== null) {
                        return new ApiError(Response::HTTP_NOT_FOUND, sprintf("Id '%s' could not be found!", $identifier));
                    }
                    break;
                case Response::HTTP_UNAUTHORIZED:
                    return new ApiError(Response::HTTP_UNAUTHORIZED, sprintf("Id '%s' could not be found or access denied!", $identifier));
            }
            if ($apiException->getCode() >= 500) {
                return new ApiError(Response::HTTP_BAD_GATEWAY, 'failed to get organizations from Campusonline');
            }
        }

        return new ApiError(Response::HTTP_INTERNAL_SERVER_ERROR, 'failed to get course(s): '.$apiException->getMessage());
    }
}
