# Explain DI and AOP before drawing them

The product goal is to make distributed Module configuration and implicit AOP behavior
inspectable. Whole-object-graph equivalence with Ray.Bindings / Ray.ObjectGrapher is not a
release goal. Module relationships remain useful as supporting evidence.

## Completed foundation

- `bear/app/contexts` discovers literal context candidates in saved
  `BEAR\Package\Compiler\Bootstrap::__invoke()`, `BEAR\Package\Injector::getInstance()` and
  `BEAR\Package\Injector::getOverrideInstance()` calls.
  The context is their second positional argument, or the named `context` argument;
  the application name is not a context candidate.
  It resolves imported class names, reads literal branches of ternaries and coalescing
  expressions, and reports unresolved arguments. It does not choose a context or prove
  which deployment uses one. Scanning covers public, bin, tests, src and project PSR-4
  directories within the workspace; file-count, traversal and source-size limits are reported.
  Static wrappers are recognized only when their entire body is one return forwarding a
  context parameter directly to a supported Injector call. Wrapper callers must pass that
  parameter positionally. General control flow, assignments and application Bootstrap bodies
  are not evaluated. Discovering a context in `getOverrideInstance()` does not apply its extra
  override Module to a context-only lookup; `coverage.overrideModulesApplied` remains false.
- `bear/di/bindingLookup` requires `applicationContext` and explains binding selections
  from saved application and installed vendor source. Type and qualifier filters are exact;
  `type: ""` means scalar bindings and `name: ""` means unqualified bindings.
  `overridesOnly` selects recorded conflicts (both replaced and discarded declarations).
  `resourcesOnly` selects known ResourceObject subclasses in the binding key, selected target,
  or discarded target. A provider's returned Resource type is not inferred.
- Each selected binding has its source declaration and ordered import path. Local decisions
  show retained/discarded declarations for later binds, install, constructor chaining and
  override. Local retained declarations can lose in a later decision; `selected` is separate.
  Evidence is workspace-relative even when `contextPath` selects a nested project.
- All selections are `provisional` if composition reports any unknown. Otherwise they are
  `source_selected`, which still means selection under the supported source model, not proof
  of runtime construction or successful dependency resolution. Empty results do not establish
  that the runtime injector cannot produce a dependency.
- Instance values, expression text and environment values are never returned. The LSP accepts
  an explicit environment profile; without one, environment-dependent branches remain unknown.
- Response items are paginated after filtering with the existing byte budget. Each item includes
  at most 50 decisions with a total and truncation flag. At most 100 unknowns are included with
  their independent total and truncation flag. Advance `offset` by returned item count.
- Some framework assembly steps remain version-specific recipes. Recipe origins and edges
  have no invented source line; they are distinct from source declarations.

The MCP adapter exposes `bear_app_context_list`, `bear_di_binding_lookup`, and
`bear_di_module_tree_read`. The latter projects the existing workspace module relationship
inventory; it does not expand vendor modules or determine precedence. The lookup does expand
installed vendor Modules under the supported interpretation rules. These different coverage
boundaries must remain visible. The adapter discovers engine support and returns `unsupported`
on an older engine; it does not silently substitute a declaration inventory.

## Context interaction

Start with declaration discovery when no context is known. Prefer an explicit context already
provided by the user. Otherwise inspect entry-point candidates and their source locations.
Do not select the first candidate, invent `prod-app`, or infer deployment from the presence of
one source file. Ask only when selecting among differing contexts is necessary for the answer.
The client carries the selected context explicitly in each dependent request; the server has
no hidden mutable active-context state. Null properties can be omitted by LSP serialization.

## Attribute catalog and AOP source matching

`bear/attribute/catalog` (`bear_attribute_catalog` in MCP) discovers PHP attribute
**definitions**, including unused ones, from application and installed-package Composer
PSR-4, PSR-0 and classmap roots. It is separate from the Resource attribute usage index.
It returns declaration locations, application/package origin, allowed targets, repeatability,
constructor parameter signatures and verbatim source docblocks (bounded to 4,000 bytes).
Constructor default values are omitted. The server does not generate descriptions.

Without `applicationContext`, the catalog does not select or evaluate a context. With one,
it adds composed AOP condition references and interceptor `invoke()` locations. References
include conditions under logical negation and declarations that another pointcut can replace;
`aop_condition_reference` is deliberately not proof that an attribute activates an interceptor.
A `Ray\Di\Di\Qualifier` marker is reported as such. Arbitrary framework consumers and usage
counts remain unresolved. Unknown consumers are not classified as AOP by naming convention.

Discovery is bounded by file count, traversal count, file size, total source bytes and parsed
attribute-source bytes. Inspect `scanTruncated` and `skippedFiles`; pagination applies only to
the discovered subset and cannot recover sources omitted by a scan limit. PHP autoload files
are not executed and declarations outside the project read boundary are not followed.

An exact attribute query first tries a bounded Composer-mapped definition lookup. When it
positively identifies the attribute, `coverage.scanMode` is `targeted_composer_definition`;
it need not scan unrelated vendor files. Otherwise it falls back to `bounded_composer_scan`
and preserves incomplete-lookup flags. File counts cover lookup and fallback attempts (a
cached declaration needs no additional file read), not the size of the installed project.
This optimization does not make the unfiltered catalog complete.

`bear/aop/applications` (`bear_aop_applications` in MCP) requires `applicationContext` and
accepts Resource URI, method, interceptor and attribute filters. It follows a source-selected Resource
class binding, including a replacement, and reports matching public `on*` request handlers
by default. An exact `method` filter inspects another public method explicitly.
`coverage.methodScope` records this boundary. Results include ordered interceptors, matcher
conditions and the originating Module declaration/import path.
Providers and instance bindings are left unresolved rather than applying matchers to the
original Resource class. Inherited methods retain their declaration's attributes; trait
methods are not expanded yet. Unreadable class hierarchies do not become negative matches.

The `ray_aop_php_attribute_onion` ordering model follows the reviewed PHP-attribute
implementation of Ray.Aop `Bind::getAnnotationPointcuts()` and `MethodMatch`: direct annotated
method conditions are first replaced by annotation key, then priority pointcuts are evaluated,
then remaining annotated pointcuts in method-attribute order, then the remainder in declaration
order. Interceptor duplicates are preserved. This model is not a claim of compatibility with
arbitrary installed versions or the legacy docblock-annotation mode.

`source_matched` means a match under that source model. `provisional`, unresolved pointcuts,
composition unknowns, and known final-class/method weaving blockers must be preserved in clients.
Neither state establishes that weaving succeeds or that a request actually runs the chain.
An interceptor/attribute filter selects known matches, so an empty filtered list is not evidence
of absence when unknowns remain. Read both `unknownTotal` and `unresolvedPointcutTotal`.
Method pages, per-method chains and unknown lists have independent bounds and totals.

The legacy totals count occurrences, not distinct defects. Application unknowns are collected
over evaluated methods **before** interceptor/attribute result filters. `unknownTotal` combines
composition, Resource inspection and method-application occurrences. `unresolvedPointcutTotal`
combines unresolved composed registrations with method-application occurrences (including
ordering uncertainty); it is not a count of distinct pointcut declarations.

`unknownSummary` separates composition, Resource and application occurrences, reports evaluated
and filter-matched method counts, and groups occurrences by reason and declaration location.
Its filter-matched counts cover all matching methods before pagination. Groups distinguish
registration count from affected method count: a declaration registered twice and evaluated on
61 methods can produce 122 occurrences. Such a group identifies a shared source location, not
proof of a single underlying defect. Summary counts are computed before the raw unknown-list
limit and remain available when that list is truncated.

Both queries live in the semantic engine and are exposed through LSP and MCP; no MCP-specific
matcher logic is introduced. Editor hover/CodeLens and IDEA integration are separate work.

See the [BEAR.Kata verification record](kata-verification.ja.md) for the pinned real-project
checks and the distinction between context-only source queries and test-specific overrides.

## Next reviewable steps

1. Diagnostics: flag ineffective AOP annotations only when their AOP role and the relevant
   module/matcher scope are established. Absence from an incomplete index is not a diagnostic.
2. Module inspection: combine relationships with binding/AOP declaration lists and navigation;
   a large FatModule box alone does not solve configuration discovery.
3. Extend source matching only with tested semantics: trait adaptations, remaining dynamic
   composition forms, and installed-version compatibility checks. Keep unsupported behavior
   visible rather than promoting source matches to runtime facts.

## Preserved experiments and merge boundary

The extension experiment and pending fixes are preserved on
`codex/archive-static-object-graph` (`a005da5`). The new work starts before the combined graph
commit on `codex/explain-di-bindings`; the reviewed composition foundation is `ef1a27e`.
Object Graph APIs, DOT generation, injection-point traversal and comparison tools were not
copied into this branch. They can be recovered from the archive independently.

In the MCP repository, `codex/archive-di-aop-map` preserves the earlier UI branch.
Its new `codex/explain-di-bindings` branch starts at the pre-UI commit `e036222` and adds
inspection tools without the experimental DI/AOP map. Local preview HTML files are not product
sources and must not be committed. The already merged IDEA Module Tree is unchanged.

Review the new branches, not the archive branches, for a future merge. Publishing a matching
extension version and updating the adapter dependency are separate release work; a local
checkout test does not upgrade an installed MCP server.
