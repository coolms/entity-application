# Changelog

All notable changes to `coolms/entity-application` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is described in `CONTRIBUTING.md` -- read it before assuming what a
major number means here.

!! Entries dated before 2026-09-01 were **reconstructed** from tags and commit
history when this file was created. Every entry after that is written in the
same commit as the change it describes.

## Unreleased

### Added

Tests the application had been carrying for this package since the code
moved here: `ReflectionFieldExtractorTest`. Nothing under `src/` changes.

## 2.0.0-alpha2 - 2026-09-09
### Changed

!! **This package is now `coolms/entity-application`, and its namespace nests under the
domain root.**

| before | after |
|---|---|
| `coolms/entity-module` | `coolms/entity-application` |
| `CoolMS\EntityModule\` | `CoolMS\Entity\Application\` |

`coolms/entity-module` is abandoned and points here. Update the requirement and the
imports together; nothing else about the classes changed -- same names, same
contracts, same requires.

The rule underneath: a suffix names a superstructure and the absence of one
names the subject, so the domain package keeps the root prefix and every layer
above it carries a segment equal to its suffix.

- Each widget renderer declares its key as a constant, so the key and the
  service that answers to it cannot drift apart.
- Comments, docblocks and changelogs are ascii and no longer name the phase that
  built a thing.
- Development-only files are export-ignored.

## 2.0.0-alpha1 - 2026-09-01

**A pre-release. It carries no compatibility promise**, which is the honest
statement of where the platform is: the shape is still moving, and a stable tag
would be a promise that cannot be kept yet.

Composer will not install it under default stability. Set

```json
"minimum-stability": "alpha",
"prefer-stable": true
```

in your root `composer.json`, then:

```
composer require coolms/entity-application:^2.0 coolms/entity-doctrine:^2.0
```

`prefer-stable` keeps every other dependency of yours on its newest stable
release, so this loosening applies to what actually needs it and nothing else.

!! **The adapter is part of the command, not an extra.** `coolms/entity-
module` requires a persistence implementation, which is a virtual package:
nothing provides it until you choose an implementation, and Composer reports
the virtual name, which reads like a broken package rather than a missing
argument.

!! **A per-package flag is not enough here.** `composer require
coolms/entity-application:^2.0@alpha` admits the alpha of the package it names and
**nothing behind it**, so the siblings this one pulls in still fail to resolve.
Composer reports it against the sibling, not against what you asked for.

A bare `composer require coolms/entity-application` does not install the wrong
thing quietly -- it refuses, naming `coolms/entity-persistence-
implementation`. That is a virtual package, so the message reads like a broken
dependency rather than a missing argument.

Releases are suspended while development is moving fast and there are no
external consumers of these packages. This tag establishes the baseline the
documentation describes; nothing follows it until somebody outside the project
installs one, at which point the release policy resumes.

### Fixed: the installation command in the readme names the adapter

`composer require coolms/entity-application` on its own cannot resolve. This package
requires a virtual persistence-implementation package, and only an adapter
provides one, so Composer reports that the virtual package "could not be found
in any version" -- which reads like a broken package rather than a missing
argument.

The readme now leads with the command that works:
`composer require coolms/entity-application coolms/entity-doctrine`.

### Changed: sibling constraints move to the v2 generation

- `coolms/core`: `^1.0` to `^2.0`
- `coolms/entity`: `^1.0` to `^2.0`
- `coolms/entity-persistence-implementation`: `^1.0` to `^2.0`
- `coolms/entity-doctrine` (development): `^1.0` to `^2.0`

!! **This is a minor, not a major, and that is deliberate.** This package
reached major 2 before the platform adopted a shared generation number, and it
did so while still requiring major 1 of its siblings. The v2 generation of those
siblings is **code-identical** to v1 -- their major moved to mark the
generation, not to break anything -- so nothing a caller can reach has changed
here.

**Upgrading:** if your own `composer.json` pins any of those packages at
`^1.0`, widen it to `^2.0`. Otherwise there is nothing to do.

The constraints on `coolms/dtmpl` and `coolms/rql` are unchanged. Those are
standalone libraries and do not take the platform generation.

## 2.0.0 - 2026-08-26

### Changed

Require `coolms/dtmpl` `^2.0`. DTMPL 2.0 encodes output by default and renamed
the verbatim block; a major here rather than a minor because moving a consumer
across that boundary is a break for them, whether or not this package's own API
moved.

Also added the CI, version, PHP and licence badges to the readme.

## 1.0.0 - 2026-08-18

First release. The platform composition layer over `coolms/entity`: reflection
field extraction, the tagged repository registry, the extras-flattening
normalizer, and the `entity:find` widget renderers.
