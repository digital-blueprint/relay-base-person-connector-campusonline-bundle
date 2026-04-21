# Changelog

## Unreleased

## v0.1.9

- Add employeeFaxNumber local data source attribute
- Add roomIdentifier and contactOrganizationIdentifier to employeeWorkAddress local data source attribute
- Prepare campusonline-api cache

## v0.1.8

- implement request caching of users representing the current persons of the current result
- add local data source attributes "employeeAdditionalInformation" and "employeeOfficeHours"

## v0.1.7

- offer two new methods getPersonIdentifierByUsername and getPersonIdentifierByEmail 
to fetch the person identifier by username or email address

## v0.1.6

- offer a method to add persons on cache re-creation

## v0.1.5

- also provide people with alumni accounts

## v0.1.3

- fix titleSuffix not written to cache table

## v0.1.2

- Cache current person
- Add unit tests

## v0.1.1

- Fix fetching the currently logged in person

## v0.1.0

- Implement the PersonProviderInterface from the person bundle
- Cache person data in the database
- Add local data support
- Add filter support
- Add health checks for the CO apis
- Add a RecreatePersonCachePostEvent which allows subscribers to add persons to the staging table on cache recreation
