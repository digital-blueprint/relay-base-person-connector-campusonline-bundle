<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\DependencyInjection\Configuration;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\DependencyInjection\DbpRelayBasePersonConnectorCampusonlineExtension;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPerson;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPersonStaging;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\EventSubscriber\PersonEventSubscriber;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service\PersonProvider;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\TestUtils\TestAuthorizationService;
use Dbp\Relay\CoreBundle\TestUtils\TestEntityManager;
use Doctrine\ORM\EntityManager;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * TODO: add more persons to responses for reasonable pagination, search and filter tests.
 */
class PersonProviderTest extends ApiTestCase
{
    private const TEST_USER_IDENTIFIER = '019ce14a-607b-7220-8a50-21a4dc9fdaf8';

    private const EMAIL_ATTRIBUTE = 'email';
    private const EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE = 'employeeWorkAddress';

    private ?PersonProvider $personProvider = null;
    private ?PersonEventSubscriber $personEventSubscriber = null;
    private ?EntityManager $entityManager = null;

    private static function getConfig(): array
    {
        return [
            Configuration::CURRENT_PERSON_IDENTIFIER_EXPRESSION_ATTRIBUTE => Configuration::CURRENT_PERSON_IDENTIFIER_EXPRESSION_ATTRIBUTE_DEFAULT,
            Configuration::CAMPUS_ONLINE_NODE => [
                Configuration::BASE_URL_NODE => 'https://campusonline.at/campusonline/ws/public/rest/',
                Configuration::CLIENT_ID_NODE => 'client',
                Configuration::CLIENT_SECRET_NODE => 'secret',
            ],
            'local_data_mapping' => [
                [
                    'local_data_attribute' => self::EMAIL_ATTRIBUTE,
                    'source_attribute' => CachedPerson::EMAIL_COLUMN_NAME,
                    'default_value' => '',
                ],
                [
                    'local_data_attribute' => self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE,
                    'source_attribute' => PersonEventSubscriber::EMPLOYEE_POSTAL_ADDRESS_SOURCE_ATTRIBUTE,
                    'default_value' => '',
                ],
            ],
        ];
    }

    private static function createMockAuthServerResponses(): array
    {
        return [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], '{"authServerUrl": "https://auth-server.net/"}'),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], '{"token_endpoint": "https://token-endpoint.net/"}'),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], '{"access_token": "token", "expires_in": 3600, "token_type": "Bearer"}'),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::bootKernel()->getContainer();

        $this->entityManager = TestEntityManager::setUpEntityManager($container,
            DbpRelayBasePersonConnectorCampusonlineExtension::ENTITY_MANAGER_ID);

        $eventDispatcher = new EventDispatcher();
        $this->personProvider = new PersonProvider($this->entityManager, $eventDispatcher);
        $this->personProvider->setLogger(new NullLogger());
        $this->personProvider->setConfig(self::getConfig());

        $this->personEventSubscriber = new PersonEventSubscriber($this->personProvider);
        $this->personEventSubscriber->setConfig(self::getConfig());
        $eventDispatcher->addSubscriber($this->personEventSubscriber);

        $this->recreatePersonCache();
        $this->login();
    }

    public function testGetCurrentPerson(): void
    {
        $currentPerson1 = $this->personProvider->getCurrentPerson();
        $this->assertSame(self::TEST_USER_IDENTIFIER, $currentPerson1->getIdentifier());
        $this->assertNull($currentPerson1->getLocalData());

        $currentPerson2 = $this->personProvider->getCurrentPerson();
        $this->assertSame(self::TEST_USER_IDENTIFIER, $currentPerson2->getIdentifier());
        $this->assertNull($currentPerson2->getLocalData());
        $this->assertSame($currentPerson1, $currentPerson2);

        // same request:
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE]);
        $currentPerson3 = $this->personProvider->getCurrentPerson($options);
        $this->assertSame(self::TEST_USER_IDENTIFIER, $currentPerson3->getIdentifier());
        $this->assertCount(1, $currentPerson3->getLocalData());
        $this->assertSame('max.mustermann@someuni.at', $currentPerson3->getLocalData()[self::EMAIL_ATTRIBUTE]);
        $this->assertNotSame($currentPerson1, $currentPerson3);
    }

    public function testGetPerson(): void
    {
        $person = $this->personProvider->getPerson(self::TEST_USER_IDENTIFIER);
        $this->assertSame(self::TEST_USER_IDENTIFIER, $person->getIdentifier());
        $this->assertNull($person->getLocalData());
    }

    public function testGetPersonWithLocalData(): void
    {
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE]);
        $person = $this->personProvider->getPerson(self::TEST_USER_IDENTIFIER, $options);
        $this->assertSame(self::TEST_USER_IDENTIFIER, $person->getIdentifier());
        $this->assertCount(1, $person->getLocalData());
        $this->assertSame('max.mustermann@someuni.at', $person->getLocalData()[self::EMAIL_ATTRIBUTE]);
    }

    public function testGetPersonWithLocalDataNewRequest(): void
    {
        $this->mockPersonClaimsApiResponse(); // getting employee address should trigger a new api request

        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE, self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]);
        $person = $this->personProvider->getPerson(self::TEST_USER_IDENTIFIER, $options);
        $this->assertCount(2, $person->getLocalData());
        $this->assertSame('max.mustermann@someuni.at', $person->getLocalData()[self::EMAIL_ATTRIBUTE]);

        $address = $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE];
        $this->assertEquals('Graz', $address['city']);
        $this->assertEquals('AT', $address['country']);
        $this->assertEquals('8010', $address['postalCode']);
        $this->assertEquals('Street 123', $address['street']);
        $this->assertEquals('PA', $address['addressTypeKey']);
    }

    public function testGetPersons(): void
    {
        $persons = $this->personProvider->getPersons(1, 10);
        $this->assertCount(1, $persons);
        $person = $persons[0];
        $this->assertSame(self::TEST_USER_IDENTIFIER, $person->getIdentifier());
        $this->assertSame('Max', $person->getGivenName());
        $this->assertSame('Mustermann', $person->getFamilyName());
    }

    public function testGetPersonsPagination(): void
    {
        $persons = $this->personProvider->getPersons(2, 1);
        $this->assertCount(0, $persons);
    }

    public function testGetPersonsWithLocalData(): void
    {
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE]);
        $persons = $this->personProvider->getPersons(1, 10, $options);
        $this->assertCount(1, $persons);
        $person = $persons[0];
        $this->assertSame(self::TEST_USER_IDENTIFIER, $person->getIdentifier());
        $this->assertSame('Max', $person->getGivenName());
        $this->assertSame('Mustermann', $person->getFamilyName());
        $this->assertCount(1, $person->getLocalData());
        $this->assertSame('max.mustermann@someuni.at', $person->getLocalData()[self::EMAIL_ATTRIBUTE]);
    }

    public function testGetPersonsWithLocalDataNewRequest(): void
    {
        $this->mockPersonClaimsApiResponse(); // getting employee address should trigger a new api request

        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE, self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]);
        $persons = $this->personProvider->getPersons(1, 10, $options);
        $this->assertCount(1, $persons);
        $person = $persons[0];
        $this->assertSame(self::TEST_USER_IDENTIFIER, $person->getIdentifier());
        $this->assertSame('Max', $person->getGivenName());
        $this->assertSame('Mustermann', $person->getFamilyName());
        $this->assertCount(2, $person->getLocalData());
        $this->assertSame('max.mustermann@someuni.at', $person->getLocalData()[self::EMAIL_ATTRIBUTE]);

        $address = $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE];
        $this->assertEquals('Graz', $address['city']);
        $this->assertEquals('AT', $address['country']);
        $this->assertEquals('8010', $address['postalCode']);
        $this->assertEquals('Street 123', $address['street']);
        $this->assertEquals('PA', $address['addressTypeKey']);
    }

    public function testGetPersonsWithSearchParameter(): void
    {
        $options = [
            Person::SEARCH_PARAMETER_NAME => 'max',
        ];
        $persons = $this->personProvider->getPersons(1, 10, $options);
        $this->assertCount(1, $persons);
        $person = $persons[0];
        $this->assertSame(self::TEST_USER_IDENTIFIER, $person->getIdentifier());
        $this->assertSame('Max', $person->getGivenName());
        $this->assertSame('Mustermann', $person->getFamilyName());

        $options = [
            Person::SEARCH_PARAMETER_NAME => 'max foo',
        ];
        $persons = $this->personProvider->getPersons(1, 10, $options);
        $this->assertCount(0, $persons);

        $options = [
            Person::SEARCH_PARAMETER_NAME => 'foo',
        ];
        $persons = $this->personProvider->getPersons(1, 10, $options);
        $this->assertCount(0, $persons);
    }

    private function mockResponses(): void
    {
        $responses = [...self::createMockAuthServerResponses(),
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__.'/users_api_response.json')),
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__.'/person_claims_api_response.json')),
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $this->personProvider->setClientHandler($stack);
    }

    private function mockPersonClaimsApiResponse(): void
    {
        $responses = [...self::createMockAuthServerResponses(),
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__.'/person_claims_api_response.json')),
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $this->personProvider->setClientHandler($stack);
    }

    private function recreatePersonCache(): void
    {
        $this->mockResponses();
        try {
            // this is expected to fail, since sqlite does not support some operations
            $this->personProvider->recreatePersonsCache();
        } catch (\Throwable) {
            $personsLiveTable = CachedPerson::TABLE_NAME;
            $personsStagingTable = CachedPersonStaging::TABLE_NAME;
            $personsTempTable = 'organizations_old';
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement("ALTER TABLE $personsLiveTable RENAME TO $personsTempTable;");
            $connection->executeStatement("ALTER TABLE $personsStagingTable RENAME TO $personsLiveTable;");
            $connection->executeStatement("ALTER TABLE $personsTempTable RENAME TO $personsStagingTable;");
        } finally {
            $this->personProvider->reset(); // ensure new api connection is created on subsequent requests
        }
    }

    private function login(?string $userIdentifier = self::TEST_USER_IDENTIFIER, array $userAttributes = []): void
    {
        TestAuthorizationService::setUp($this->personProvider, $userIdentifier, $userAttributes);
    }
}
