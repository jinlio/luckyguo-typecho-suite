# Repository Working Rules

This repository is the public, reusable Typecho Suite. Keep personal domains, identities, production data, credentials, server paths, and private deployment details out of it.

## Change records

- Every code, configuration, deployment, or documentation change must append a concise entry to `CHANGELOG.md` in the same change.
- Each entry must include the date, affected files or area, user-visible effect, and verification performed.
- Do not create separate per-change notes when the change belongs in the shared log.

## Implementation and release

- Prefer backend settings and documented deployment scripts over hard-coded site-specific values.
- Preserve graceful degradation when optional search, monitoring, statistics, or external avatar services are unavailable.
- Do not expose secrets in source, screenshots, examples, test fixtures, or logs.
- Run `./tests/static-check.sh`, shell and JavaScript syntax checks, and targeted runtime checks before release.
- Run PHP lint in a target PHP environment because PHP is not guaranteed to exist on the development workstation.
- Keep README.md and README.zh-CN.md aligned when installation or user-facing behavior changes.
- Do not claim a release is verified unless the relevant mobile, desktop, light-mode, dark-mode, empty-state, and failure-state paths were checked.
