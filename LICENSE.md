# Licensing

## Examora

Copyright (c) 2026 Examora. All rights reserved.

The Examora application code — everything under `backend/app`,
`backend/database`, `backend/routes`, `backend/tests`, `web/` and `docs/` — is
proprietary and not licensed for redistribution.

## Third-party components

| Component | Licence | Where |
|---|---|---|
| [Wave](https://github.com/thedevdojo/wave) SaaS starter kit | MIT | `backend/wave/`, `backend/resources/themes/` |
| [Laravel](https://laravel.com) | MIT | Composer dependency |
| [Filament](https://filamentphp.com) | MIT | Composer dependency |
| [React](https://react.dev), [Vite](https://vite.dev), [Tailwind CSS](https://tailwindcss.com) | MIT | npm dependencies |

Wave is vendored into `backend/wave/` because that is how `devdojo/wave`
distributes itself — as a project template rather than a package. Its MIT licence
text is retained at `backend/LICENSE.md`. Wave's own theme files under
`backend/resources/themes/` are likewise MIT.

Full dependency licences are in `backend/composer.lock` and
`web/package-lock.json`.

## Question content

The seeded question corpus in `BcsPreliminarySeeder` is **representative sample
data written for development**, not a transcription of official BCS past papers.

Real past-paper content carries its own copyright and must be licensed before
use. See [`docs/roadmap.md`](docs/roadmap.md).
