# Contributing

Thanks for your interest in Lintune. This project is in early development and contributions are very welcome — whether that's code, ideas, bug reports, or documentation improvements.

## Ways to contribute

- **Bug reports** — open an issue with as much detail as possible (steps to reproduce, error messages, environment)
- **Feature ideas** — open a discussion or issue describing the use case, not just the feature
- **Code** — see below
- **Documentation** — improvements to the docs are always appreciated

## Before you start coding

For anything beyond a small bug fix, please open an issue first to discuss the approach. This avoids wasted effort if the direction doesn't fit the project.

## Stack

- PHP 8.3+ / Laravel 13
- MySQL
- Keycloak (Admin REST API)
- Mailcow (REST API)
- Bootstrap 5 + AdminLTE 4 (no build step for the admin UI)

## Setup for local development

```bash
git clone https://github.com/scraane/lintune-admin
cd lintune-admin
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

You will need a running Keycloak instance. See [docs/install.md](docs/install.md) for full setup instructions.

## Code style

- Follow existing conventions in the codebase
- Keep controllers thin — business logic in services where it makes sense
- Minimal comments — write readable code instead
- No unnecessary dependencies

## Pull requests

- One concern per PR
- Include a clear description of what the change does and why
- If it touches the setup flow, test it against a clean Keycloak instance

## License

By contributing you agree that your contributions will be licensed under the [MIT License](LICENSE).
