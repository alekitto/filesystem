# AGENTS.md

## Project overview
- PHP 8.3 filesystem abstraction library (PSR-4 `Kcs\\Filesystem\\`).
- Implementations: local filesystem, Async S3, Google Cloud Storage, plus a PHP stream wrapper and Symfony bundle.

## Repo layout
- `lib/`: main source (interfaces, implementations, stream wrapper, Symfony integration).
- `tests/`: PHPUnit tests (unit + integration).
- `data/`: fixture files used by tests.
- `infection/` and `infection.json.dist`: mutation testing outputs/config.

## Key modules
- `lib/Filesystem*.php`: core reader/writer interfaces.
- `lib/Local/`: local filesystem implementation and Unix permissions helpers.
- `lib/AsyncS3/`: Async AWS S3 adapter + visibility converters.
- `lib/GCS/`: Google Cloud Storage adapter.
- `lib/StreamWrapper/`: PHP stream wrapper implementation.
- `lib/Symfony/`: bundle + DI wiring.

## Conventions
- `declare(strict_types=1)` everywhere; prefer typed properties and return types.
- Errors are wrapped in `Kcs\\Filesystem\\Exception` types.
- Path handling centralized in `PathNormalizer` and adapter-specific prefixing.
- Use Conventional Commits for commit messages (e.g., `feat: ...`, `fix: ...`).

## Common commands
- Install deps: `composer install`
- Unit tests: `vendor/bin/phpunit`
- Static analysis: `composer run phpstan`
- Coding standards: `composer run cscheck` (fix: `composer run csfix`)
- Mutation testing: `vendor/bin/infection --configuration=infection.json.dist`

## Integration test notes
- `tests/AsyncS3/AsyncS3FilesystemIntegrationTest.php` can spin up MinIO via Docker if no env is provided.
- Supported env vars: `KCS_FILESYSTEM_S3_ENDPOINT`, `KCS_FILESYSTEM_S3_BUCKET`, `KCS_FILESYSTEM_S3_ACCESS_KEY`, `KCS_FILESYSTEM_S3_SECRET_KEY`, `TEST_TOKEN`, `PHP_DOCKER`, `DOCKER_HOST`.
