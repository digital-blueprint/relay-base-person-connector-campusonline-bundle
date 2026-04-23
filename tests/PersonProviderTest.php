<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service\PersonProvider;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\TestUtils\TestPersonProviderFactory;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\TestUtils\TestAuthorizationService;

/**
 * TODO: add more persons to responses for reasonable pagination, search and filter tests.
 */
class PersonProviderTest extends ApiTestCase
{
    private const STAFF_USER_IDENTIFIER = TestPersonProviderFactory::STAFF_USER_IDENTIFIER;

    private const EMAIL_ATTRIBUTE = 'email';
    private const EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE = 'employeeWorkAddress';

    private ?PersonProvider $personProvider = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->personProvider = TestPersonProviderFactory::createTestPersonProvider(self::bootKernel()->getContainer());
        $this->login();
    }

    public function testGetCurrentPerson(): void
    {
        $currentPerson1 = $this->personProvider->getCurrentPerson();
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $currentPerson1->getIdentifier());
        $this->assertNull($currentPerson1->getLocalData());

        $currentPerson2 = $this->personProvider->getCurrentPerson();
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $currentPerson2->getIdentifier());
        $this->assertNull($currentPerson2->getLocalData());
        $this->assertSame($currentPerson1, $currentPerson2);

        // same request, but with local data requested
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE]);
        $currentPerson3 = $this->personProvider->getCurrentPerson($options);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $currentPerson3->getIdentifier());
        $this->assertCount(1, $currentPerson3->getLocalData());
        $this->assertSame('max.mustermann@someuni.at', $currentPerson3->getLocalData()[self::EMAIL_ATTRIBUTE]);
        $this->assertNotSame($currentPerson1, $currentPerson3);

        // same request, but with different local data requested
        TestPersonProviderFactory::mockPersonClaimsApiResponse($this->personProvider); // getting employee address should trigger a new api request
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]);
        $currentPerson4 = $this->personProvider->getCurrentPerson($options);
        $this->assertCount(1, $currentPerson4->getLocalData());
        $address = $currentPerson4->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE];
        $this->assertEquals('Graz', $address['city']);
        $this->assertEquals('AT', $address['country']);
        $this->assertEquals('8010', $address['postalCode']);
        $this->assertEquals('Street 123', $address['street']);
        $this->assertEquals('PA', $address['addressTypeKey']);
        $this->assertNotSame($currentPerson1, $currentPerson4);
    }

    public function testGetCurrentPersonWithLocalData(): void
    {
        TestPersonProviderFactory::mockPersonClaimsApiResponse($this->personProvider); // getting employee address should trigger a new api request

        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE, self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]);
        $currentPerson1 = $this->personProvider->getCurrentPerson($options);
        $this->assertCount(2, $currentPerson1->getLocalData());
        $this->assertSame('max.mustermann@someuni.at', $currentPerson1->getLocalData()[self::EMAIL_ATTRIBUTE]);

        $address = $currentPerson1->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE];
        $this->assertEquals('Graz', $address['city']);
        $this->assertEquals('AT', $address['country']);
        $this->assertEquals('8010', $address['postalCode']);
        $this->assertEquals('Street 123', $address['street']);
        $this->assertEquals('PA', $address['addressTypeKey']);

        // same local data attributes -> same instance should be returned and no new api request should be made
        $currentPerson2 = $this->personProvider->getCurrentPerson($options);
        $this->assertCount(2, $currentPerson2->getLocalData());
        $this->assertSame($currentPerson1, $currentPerson2);
    }

    public function testGetCurrentPersonNotFound(): void
    {
        $this->login('non-existing-user');
        $this->assertNull($this->personProvider->getCurrentPerson());
    }

    public function testGetPerson(): void
    {
        $person = $this->personProvider->getPerson(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $person->getIdentifier());
        $this->assertNull($person->getLocalData());
    }

    public function testGetPersonWithLocalData(): void
    {
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE]);
        $person = $this->personProvider->getPerson(self::STAFF_USER_IDENTIFIER, $options);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $person->getIdentifier());
        $this->assertCount(1, $person->getLocalData());
        $this->assertSame('max.mustermann@someuni.at', $person->getLocalData()[self::EMAIL_ATTRIBUTE]);
    }

    public function testGetPersonWithLocalDataNewRequest(): void
    {
        TestPersonProviderFactory::mockPersonClaimsApiResponse($this->personProvider); // getting employee address should trigger a new api request

        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE, self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]);
        $person = $this->personProvider->getPerson(self::STAFF_USER_IDENTIFIER, $options);
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
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $person->getIdentifier());
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
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $person->getIdentifier());
        $this->assertSame('Max', $person->getGivenName());
        $this->assertSame('Mustermann', $person->getFamilyName());
        $this->assertCount(1, $person->getLocalData());
        $this->assertSame('max.mustermann@someuni.at', $person->getLocalData()[self::EMAIL_ATTRIBUTE]);
    }

    public function testGetPersonsWithLocalDataNewRequest(): void
    {
        TestPersonProviderFactory::mockPersonClaimsApiResponse($this->personProvider); // getting employee address should trigger a new api request

        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE, self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]);
        $persons = $this->personProvider->getPersons(1, 10, $options);
        $this->assertCount(1, $persons);
        $person = $persons[0];
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $person->getIdentifier());
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
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $person->getIdentifier());
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

    public function testGetPersonIdentifierByUsername(): void
    {
        TestPersonProviderFactory::mockUserApiResponse($this->personProvider);

        $personIdentifier = $this->personProvider->getPersonIdentifierByUsername('maxm');
        $this->assertEquals('staff-id', $personIdentifier);

        TestPersonProviderFactory::mockEmptyUserApiResponse($this->personProvider);

        $this->assertNull($this->personProvider->getPersonIdentifierByUsername('foo'));
    }

    public function testGetPersonIdentifierByEmail(): void
    {
        TestPersonProviderFactory::mockUserApiResponse($this->personProvider);

        $personIdentifier = $this->personProvider->getPersonIdentifierByEmail('max.mustermann@someuni.at');
        $this->assertEquals('staff-id', $personIdentifier);

        TestPersonProviderFactory::mockEmptyUserApiResponse($this->personProvider);

        $this->assertNull($this->personProvider->getPersonIdentifierByEmail('foo'));
    }

    public function testGetUserFromApiCached(): void
    {
        TestPersonProviderFactory::mockUserApiResponse($this->personProvider);

        $user = $this->personProvider->getUserFromApiCached(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $user->getPersonUid());
        $this->assertSame('max.mustermann@someuni.at', $user->getEmail(0));
        $this->assertSame('maxm', $user->getUsername(0));
    }

    public function testGetUserFromApiCachedNotFound(): void
    {
        TestPersonProviderFactory::mockUserApiResponse404($this->personProvider);

        try {
            $this->personProvider->getUserFromApiCached('foo');
            $this->fail('expected ApiError not thrown');
        } catch (ApiError $apiError) {
            $this->assertSame(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND, $apiError->getStatusCode());
        }
    }

    public function testRequestCacheCurrentResultUsers(): void
    {
        $this->personProvider->getPerson(self::STAFF_USER_IDENTIFIER); // caches the person ids of current request result

        TestPersonProviderFactory::mockUserApiResponse($this->personProvider);
        $this->personProvider->requestCacheCurrentResultUsers();

        // NOTE: no more requests must be made
        $user = $this->personProvider->getUserFromApiCached(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $user->getPersonUid());
        $this->assertSame('max.mustermann@someuni.at', $user->getEmail(0));
        $this->assertSame('maxm', $user->getUsername(0));
    }

    public function testIsCurrentUserAnEmployee(): void
    {
        $this->assertTrue($this->personProvider->isCurrentUserAnEmployee());
    }

    public function testIsCurrentUserAnEmployeeUndefined(): void
    {
        $this->login('non-existing-user');
        $this->assertNull($this->personProvider->isCurrentUserAnEmployee());
    }

    public function testIsCurrentUserAStudent(): void
    {
        $this->assertFalse($this->personProvider->isCurrentUserAStudent());
    }

    public function testIsCurrentUserAStudentUndefined(): void
    {
        $this->login('non-existing-user');
        $this->assertNull($this->personProvider->isCurrentUserAStudent());
    }

    public function testIsCurrentUserAnAlumni(): void
    {
        $this->assertFalse($this->personProvider->isCurrentUserAnAlumni());
    }

    public function testIsCurrentUserAnAlumniUndefined(): void
    {
        $this->login('non-existing-user');
        $this->assertNull($this->personProvider->isCurrentUserAnAlumni());
    }

    private function login(?string $userIdentifier = self::STAFF_USER_IDENTIFIER, array $userAttributes = []): void
    {
        TestAuthorizationService::setUp($this->personProvider, $userIdentifier, $userAttributes);
    }
}
