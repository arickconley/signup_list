# Signup Sheets

A mobile-first Laravel application for creating and sharing signup sheets.

The repository currently contains the initial Laravel Livewire scaffold. Product behavior is defined in [SPEC.md](SPEC.md), with canonical domain terms in [CONTEXT.md](CONTEXT.md).

## Stack

- PHP 8.3+
- Laravel 13
- Livewire 4 with native Blade components
- Tailwind CSS 4
- SQLite
- Pest and Larastan

## Local setup

```sh
composer run setup
composer run dev
```

`composer run setup` installs PHP and JavaScript dependencies, creates `.env`, generates an application key, migrates SQLite, and builds frontend assets.

## Quality checks

```sh
composer test
npm run build
```

## Documentation

- [Product specification](SPEC.md)
- [Domain glossary](CONTEXT.md)
- [Agent configuration](docs/agents/)

## Deployment constraint

v1 targets one application instance with a persistent SQLite disk. See the specification for queue, scheduler, backup, and email requirements.

## Frontend

The interface uses project-owned Blade components, Tailwind CSS, and small Alpine.js interactions provided by Livewire. It intentionally avoids proprietary or general-purpose UI component libraries.

## License

Signup Sheets is licensed under the [GNU Affero General Public License v3.0 or later](LICENSE). Commercial use is permitted; modified network deployments must offer corresponding source to their users under the AGPL.
