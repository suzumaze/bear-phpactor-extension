# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed

- CI now installs the committed dependency lock for reproducible pull-request checks,
  while a separate scheduled and manually runnable job exercises the latest allowed
  dependencies.
- The English and Japanese READMEs now list every Semantic API v1 request and the
  standalone verification tools.

### Fixed

- Recognize Twig whitespace-control syntax in template references, verbatim blocks,
  and Embed expressions.
- Resolve aliased Embed and JsonSchema attributes by their fully qualified names and
  ignore unrelated attributes with the same short names; all supported attribute
  scanners now share the same PHP name-resolution helper.
- Reuse already parsed Resource facts during project contract diagnostics instead of
  resolving and reading the same Resource again for each method.
- Refresh imported application package mappings after Composer metadata changes are
  reported through the LSP file-event listener.
- Allow valid filenames containing consecutive dots while applying canonical
  workspace-boundary checks consistently to Resource resolution.
- Preserve editor navigation to ImportApp packages installed as Composer path-repository
  symlinks, while keeping read-only Semantic API requests inside the workspace boundary.

## [0.1.8] - 2026-09-22

### Added

- `bear/project/contractCoverage` for bounded, saved-source-only JSON Schema and
  ALPS adoption coverage across Resource methods, distinguishing absent, dynamic,
  unresolved, available, and non-applicable contract surfaces without reporting
  optional gaps as project errors.

### Changed

- Project diagnostics and contract coverage now use stable offset pagination with a
  default 100-item page and an approximate serialized-byte budget; diagnostics still
  accepts its published 1–200 `limit` range, and contract coverage can select adoption
  gaps without losing the complete project summary.
- Project diagnostics skip Schema and ALPS reference checks when their convention roots
  are absent, reporting the omissions in `skippedChecks`; name-difference details
  include five sample names per surface alongside complete totals.
- Project diagnostics and contract coverage now scan the complete Resource inventory;
  their response page limits no longer discard Resources after the first 200.
- `bear/resource/list` and `bear/resource/attributeIndex` now accept `offset` and return
  it, so every Resource remains reachable through stable bounded pages.
- The included semantic stdio client now disables Phpactor auto-configuration so a
  read-only query cannot create or rewrite workspace configuration.

## [0.1.7] - 2026-09-20

### Added

- `bear/project/diagnostics` for a bounded, saved-source-only scan of statically
  provable Resource, Route, SQL, JSON Schema, ALPS, template, parse, relation, and
  contract inconsistencies across a project.
- Project diagnostics report Resource inventory truncation and skipped check kinds,
  preserve partial results when individual inputs are broken, and avoid duplicate
  diagnostics for Link and Embed targets.

### Changed

- `bear/template/forResource` now preserves the resolved Resource, ordered convention
  paths checked, and Resource provenance when the Resource exists but its template does
  not. The response remains `not_found` with no successful `data`; an optional `partial`
  member explains that narrower failure without changing the Semantic API v1 `data`
  contract. Null members remain omitted by Phpactor's stdio serializer.
- Resource attribute responses now expose an `argumentPolicy` that distinguishes
  explicit source arguments from constructor defaults, which are not expanded.

## [0.1.6] - 2026-09-17

### Added

- `bear/resource/attributes` for inspecting saved Resource method attributes and
  arguments without executing application PHP.
- `bear/resource/attributeIndex` for a deterministic, bounded inventory of Resource
  attribute facts across the workspace.
- `bear/contract/compare` for comparing exact request-name presence across a Resource
  method, request JSON Schema, and ALPS descriptor without claiming type or semantic
  equivalence.

### Changed

- Semantic API version 1 discovery and its contract snapshot now advertise nineteen
  read-only `bear/*` requests.
- Resource and Schema facts expose the additional static metadata needed for bounded
  contract-surface comparisons.
- Resource URI document links reuse one parsed syntax tree and one project lookup for
  all links in a document.

### Fixed

- Preserve numeric-only JSON Schema property names as strings throughout contract
  comparison results instead of raising a type error.
- Match incoming Link and Embed relations by their resolved Resource file so equivalent
  URI spellings and nested application contexts remain correct.
- Discover ImportApp declarations below hidden project ancestors, skip excluded or
  unreadable child directories safely, and refresh mappings after LSP file changes.
- Keep explicitly imported application namespaces out of the host application's `self`
  Resource inventory while retaining their configured import host.
- Read valid positional Link and Embed attribute arguments according to the current
  BEAR.Resource constructor signatures.
- Bound parsed Resource facts to a 128-entry least-recently-used cache.

## [0.1.5] - 2026-09-14

### Added

- Transport-independent semantic query services for Resource URIs, Routes, SQL IDs,
  Twig/Qiq templates, ALPS descriptors, and JSON Schemas.
- Sixteen read-only `bear/*` Language Server requests for identifier-based queries,
  project capabilities, Resource inventory and descriptions, incoming relations, and
  references without a document position.
- Semantic API version 1 discovery through `bear/project/info`, with a versioned
  contract snapshot for request signatures, response keys, envelopes, and statuses.
- Standard LSP Hover for Resource URIs, Routes, SQL IDs, templates, ALPS descriptors,
  and explicit JSON Schemas while preserving Phpactor's normal PHP Hover behavior.
- Standard LSP References for Routes, SQL IDs, explicit JSON Schemas, ALPS attribute
  usages, and static Twig/Qiq template references.
- A headless CLI client for sending custom requests to a real Phpactor stdio process.
- Real Phpactor stdio integration coverage from initialize through shutdown.

### Changed

- Existing LSP adapters delegate BEAR-specific resolution to reusable semantic query
  services instead of owning the framework rules themselves.
- Resource inventory and parsed Resource, ALPS, and Schema facts use bounded caches
  with content-based freshness checks and watched-file invalidation where supported.
- Semantic results report deterministic candidates, stable failure codes, and
  workspace-relative provenance with explicit freshness.

### Security

- Semantic queries enforce a canonical workspace boundary, reject symlink escapes and
  parent traversal, bound scanned input sizes and parser depth, and never fetch external
  ALPS or JSON Schema references.
- Invalid, missing, ambiguous, malformed, and outside-workspace inputs return structured
  failure results instead of executing application PHP or exposing exception traces.

[Unreleased]: https://github.com/suzumaze/bear-phpactor-extension/compare/v0.1.8...HEAD
[0.1.8]: https://github.com/suzumaze/bear-phpactor-extension/compare/v0.1.7...v0.1.8
[0.1.7]: https://github.com/suzumaze/bear-phpactor-extension/compare/v0.1.6...v0.1.7
[0.1.6]: https://github.com/suzumaze/bear-phpactor-extension/compare/v0.1.5...v0.1.6
[0.1.5]: https://github.com/suzumaze/bear-phpactor-extension/compare/v0.1.4...v0.1.5
