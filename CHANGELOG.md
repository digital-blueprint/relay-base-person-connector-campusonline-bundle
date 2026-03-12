# Changelog

## Unreleased

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
