# Integration harness (ephemeral, isolated)

Real-database integration tests for the email queue schema. **Never** point this
at a production, staging or existing local WordPress database.

## Safety guarantees

- The database name **must** contain `test` or `integration`.
- The table prefix **must** start with `wpint_`.
- `tests/integration/bootstrap.php` aborts if the host/DB/URL contains a
  production marker, if the prefix is wrong, or if the database already holds
  tables that do not belong to the harness.
- Credentials are generated locally per run, never printed in full, never
  committed, and removed on cleanup.
- Mail is never actually sent (external HTTP is blocked, cron disabled).
- The self-hosted updater is not contacted.

## Engine matrix

The plugin declares **WordPress 6.0+ / PHP 7.4+** but does **not** declare an
official minimum MySQL/MariaDB version. Until such a policy exists, validation
runs **provisionally** against **MySQL 8.0** and **MariaDB 10.6**. This is a
provisional target, **not** an official compatibility policy.

## Option A — WSL / Linux without containers (no root service)

```bash
# once, needs sudo (installs php, mariadb binaries, svn, composer)
sudo bash tests/integration/wsl-setup.sh

# run the suite against an ephemeral, user-owned DB in /tmp
bash tests/integration/wsl-run.sh

# remove everything the harness created
bash tests/integration/wsl-clean.sh
```

`wsl-run.sh` starts its own server process in a throwaway `/tmp` datadir (no
system service, no root), creates a dedicated database and a user limited to it,
installs the WordPress test library, runs `phpunit-integration.xml`, and collects
evidence (engine version, timezones, `SHOW CREATE TABLE`, `SHOW INDEX`, PHPUnit
output).

## Option B — Docker / Podman

```bash
cp tests/integration/.env.integration.example tests/integration/.env.integration
# edit .env.integration (throwaway values only)
bash tests/integration/start.sh
bash tests/integration/run.sh
bash tests/integration/collect.sh
bash tests/integration/clean.sh
```

All container resources use the `wp_anpa_integration_*` prefix and a dedicated
network; cleanup only removes this project's own resources.

## Option C — CI

`.github/workflows/integration.yml` runs the same matrix (MySQL 8.0 + MariaDB
10.6) on `feature/**` branches. It uses ephemeral service databases and
throwaway credentials, uploads evidence artifacts, and never publishes a
release, never modifies `main`, and never touches the update channels.

## Timezones

The harness deliberately uses **three different timezones** (database session,
PHP, WordPress) to prove that queue datetimes are stored and compared in **UTC**
and only converted for display.
