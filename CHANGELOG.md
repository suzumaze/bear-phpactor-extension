# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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

[Unreleased]: https://github.com/suzumaze/bear-phpactor-extension/compare/v0.1.3...HEAD
