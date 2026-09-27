# Explain DI and AOP before drawing them

The product goal is to make distributed Module configuration and implicit AOP behavior
inspectable. Whole-object-graph equivalence with Ray.Bindings / Ray.ObjectGrapher is not a
release goal. Module relationships remain useful as supporting evidence.

## Completed foundation

- `bear/app/contexts` discovers literal context candidates in saved
  `BEAR\Package\Bootstrap::__invoke()` and `BEAR\Package\Injector::getInstance()` calls.
  It resolves imported class names, reads literal branches of ternaries and coalescing
  expressions, and reports unresolved arguments. It does not choose a context or prove
  which deployment uses one. Scanning covers public, bin, tests, src and project PSR-4
  directories within the workspace; file-count, traversal and source-size limits are reported.
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

## Next reviewable steps

1. Attribute catalog: definitions in application/vendor source, allowed targets, constructor
   signatures, docblocks and evidenced readers. Keep this separate from the Resource attribute
   usage index. Do not classify every attribute as AOP or generate explanations in the server.
2. AOP applications: share the matcher evaluator and composed Module evidence between MCP and
   editor features. Return Resource/method, interceptors, declaration sites, and unresolved
   conditions. Only state order where composition and matching establish it.
3. Diagnostics: flag ineffective AOP annotations only when their AOP role and the relevant
   module/matcher scope are established. Absence from an incomplete index is not a diagnostic.
4. Module inspection: combine relationships with binding/AOP declaration lists and navigation;
   a large FatModule box alone does not solve configuration discovery.

The present change does not expose an AOP-application or attribute-catalog API. Existing pointcut
and attribute usage inventories remain declaration evidence, not an application result.

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
