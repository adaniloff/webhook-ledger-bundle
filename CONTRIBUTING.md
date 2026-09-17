# Contributing

Issues and pull requests are welcome.

This bundle has no local `vendor/`, no `composer.lock` - install dependencies before working on it:

```bash
composer install
```

Before opening a pull request, all of these must pass:

```bash
vendor/bin/phpstan analyse src --level=max
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/deptrac analyse
vendor/bin/phpunit
```

For now: `vendor/bin/phpunit` needs a MariaDB reachable at the `DATABASE_URL` set in `phpunit.dist.xml` - see `.github/workflows/ci.yml` for the exact database name/credentials CI uses.

Keep pull requests scoped to one change. Update `CHANGELOG.md` under `[Unreleased]` for anything user-facing.
