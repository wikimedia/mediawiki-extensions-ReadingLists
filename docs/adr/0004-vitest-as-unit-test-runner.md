# ADR 0004) Vitest as the unit test runner

Date: 2026-10

## Status

Accepted

*The migration patch is merged before this ADR. This ADR records why we moved the ReadingLists
client-side unit tests from Jest to Vitest, so the rationale for the migration is captured right
after the change lands.*

## Context

ReadingLists ships client-side Vue 3 components under 'resources/'. Components are the
bookmark button, the onboarding popovers, the anonymous CTA dialog, and the special page views.
20 unit test files under 'tests/jest/' cover these components, currently run with **Jest 29**:

- 'jest' `29.7.0` + 'jest-environment-jsdom' `29.7.0`
- '@vue/vue3-jest' to transform `.vue` single-file components (SFCs)
- '@vue/test-utils' to mount components
- '@babel/preset-env' to transform JS
- a 'jest.config.js' file, with a `moduleNameMapper` map for `ext.readingLists.*` module names,
  'codex.js', 'codex-icons', 'config.json', and stubs for 'mediawiki.storage' and 'vue-router'
- a 'jest.setup.js' file, which installs a global `mw` mock built from `jest.fn()`
- coverage collection, with per-metric thresholds (statements 50, branches 44, functions 30, lines
  50)

The test suite uses `describe`, `it`, and `expect`. It uses `jest.fn` at 141 call sites, and
`jest.mock` or `jest.doMock` at 16 call sites. It uses fake timers (`useFakeTimers`,
`advanceTimersByTime`) and `toMatchSnapshot` (11 '.snap' files).

Several problems push us to review the test runner:

- **The transform pipeline is custom and old.** Vue SFCs go through '@vue/vue3-jest'. This package
  is pinned to the Jest 29 major version. Its last commit dates to 2024, and its last release dates
  to September 2023. The transform config (Babel plus `vue-jest` globals) does not match how a
  Vite-based resource build would compile the same components. So tests compile components one way,
  and the real build compiles them another way.
- **ESM interop costs time.** Front-end dependencies at Wikimedia, notably '@wikimedia/codex', ship
  as ESM. Jest expects CommonJS by default. So we need `moduleNameMapper` shims and transform-ignore
  settings to load these ESM packages in tests.
- **Feedback is slow.** Jest re-transforms code with Babel on each run. Watch-mode iteration on the
  Vue components runs slower than it would under a Vite or esbuild pipeline.
- **TypeScript support is missing.** The project already depends on 'typescript'. But the Jest path
  has no built-in TypeScript transform. We would need to add 'ts-jest' or more Babel config to
  support it.

## Considered actions

### Stay on Jest (status quo)

Keep Jest 29, '@vue/vue3-jest', and the current 'jest.config.js' and 'jest.setup.js' files.

#### Advantages

- **This path needs no migration.** We change no test files, no snapshot files, and no `mw` mock in
  'jest.setup.js'.
- **This path matches the rest of the ecosystem.** The rest of the Wikimedia front-end, including
  Codex and MediaWiki core's client tests, uses Jest 29. Reviewers, CI Docker images, and the Jest
  globals in `eslint-config-wikimedia` all assume Jest.
- **This path is proven in Wikimedia CI.** The Quibble and CI image path for `jest --verbose` does
  work, and CI is already green for this extension.

#### Downsides

- We keep the '@vue/vue3-jest' dependency. This package trails the Vue tooling ecosystem and is tied
  to the Jest 29 major version.
- Security and maintenance risk will grow, because '@vue/vue3-jest' has no active maintainer, and
  the Jest 29 line will reach end of support at some point.
- Test-time SFC compilation stays separate from any Vite-based build we may adopt for 'resources/'.
- ESM-only dependencies still need `moduleNameMapper` shims and other workarounds.
- Watch-mode feedback stays slower than a Vite or esbuild pipeline would give us.

### Migrate to Vitest (chosen)

Replace Jest with **Vitest**. Use '@vitejs/plugin-vue' to compile SFCs. Add a 'vitest.config.mjs'
file (`environment: 'jsdom'`, `resolve.alias` in place of `moduleNameMapper`, coverage through
'@vitest/coverage-istanbul'). Add a setup file that swaps `jest.fn` for `vi.fn`.

#### Advantages

- **This path gives one standard transform for SFCs.** '@vitejs/plugin-vue' is the standard Vue 3
  compiler in the Vue ecosystem. Its maintainers keep it active. It replaces the custom
  '@vue/vue3-jest' transform.
- **The Vitest API matches the Jest API, so migration stays mechanical.** `describe`, `it`,
  `expect`, `vi.fn` versus `jest.fn`, `vi.mock` versus `jest.mock`, and fake timers map almost one
  to one between the two runners. '@vue/test-utils' works the same way under both runners. Most of
  the change is a rename from `jest.*` to `vi.*`, plus config translation.
- **This path gives first-class ESM and TypeScript support.** Vite and esbuild handle ESM-only
  dependencies such as Codex. They also handle the existing 'typescript' dependency, with no need
  for 'ts-jest' or extra Babel config.
- **This path gives faster feedback.** On-demand esbuild transforms and Vite's watch mode give
  quicker re-runs than Babel-based Jest gives.
- **`moduleNameMapper` becomes `resolve.alias`.** A build config can share this alias map; a
  Jest-only config could not.
- **This path keeps built-in coverage.** '@vitest/coverage-istanbul' gives the same instrumentation
  and the same numbers Jest produced. We choose this provider over the default V8 provider for that
  reason. The same per-metric thresholds still apply.

#### Downsides

- **This path moves ReadingLists away from the Wikimedia front-end ecosystem.** Codex and MediaWiki
  core still run Jest. With this change, ReadingLists will be one of 3 outliers. This cost is the
  largest one: it raises the bar for reviewers who know Jest but not Vitest, and it means this
  extension cannot rely on the shared Jest assumptions in `eslint-config-wikimedia` or in CI images.
- **Lint globals need a change.** The Jest environment in `eslint-config-wikimedia` supplies the
  `jest` global. Vitest needs `globals: true`, or explicit `vi` and test-global imports, plus a
  matching ESLint config, so `vi` and the test globals resolve.
- **Setup code and snapshots need rework.** The `mw` mock in 'jest.setup.js' uses `jest.fn()` and
  must move to `vi.fn()`. The existing snapshots must be regenerated and reviewed, because the two
  runners can serialize output differently.
- **CI needs validation.** We must confirm that the Wikimedia CI image and Node version run Vitest
  without issue.

## Decision

We decide to migrate the ReadingLists client-side unit tests to **Vitest**.

The suite is small, at 20 files. It also uses an API that matches Jest closely, and Vitest matches
that API almost one to one. So the one-time migration cost stays bounded and mostly mechanical. In
exchange, we gain a maintained, standard SFC transform ('@vitejs/plugin-vue'). We gain first-class
ESM and TypeScript support, which fits the Codex-based Vue stack. We gain faster feedback. We also
remove the aging '@vue/vue3-jest' dependency.

We accept the trade-off to move away from the Jest-based Wikimedia norm. We accept this for one
reason: the test runner is an internal developer-tooling choice, with no effect on the runtime or
the API surface. The Vitest API stays close enough to the Jest API that reviewer friction stays
limited to configuration. It does not extend to how we write tests.

### Migration outline

1. **Update dependencies.** Remove 'jest', 'jest-environment-jsdom', '@vue/vue3-jest', and the Babel
   test preset, if no other part of the project uses the preset. Add 'vitest', '@vitejs/plugin-vue',
   'jsdom', '@vitest/coverage-istanbul', and 'magic-string' (see step 2). Bump '@vue/test-utils' to
   its latest minor version.
2. **Replace the config.** Replace 'jest.config.js' with 'vitest.config.mjs'. The config uses ESM
   syntax, but 'package.json' has no `"type": "module"`, and Vite warns that its planned native
   config loader will reject a '.js' config in that setup. The '.mjs' extension avoids this. As
   native ESM has no `__dirname`, the config uses `import.meta.dirname` instead.
   - Set `test.environment` to `'jsdom'`.
   - Set `plugins` to `[ vue() ]`, plus two custom plugins that run before '@vitejs/plugin-vue'. One
     plugin rewrites plain resource JS. This JS uses ResourceLoader-style
     `require('ext.readingLists.*')` calls, plus CJS `require()` and `module.exports` statements.
     The plugin turns these into hoisted ESM imports, so Vite can transform the code and `vi.mock()`
     can intercept it. The other plugin does the same job for Vue SFCs, and also strips `<style
     lang="less">` blocks, because the test tree has no 'less' package installed. These two plugins
     take most of the migration effort. They replace the CJS/ESM bridging that '@vue/vue3-jest'
     handled without extra code. Both edit code through 'magic-string' and return its source map,
     so coverage and stack traces point at the original source lines.
   - Have the resource JS plugin rewrite `module.exports = { … }` to named exports plus a default
     export of the same object, because the hoisted `import X from` statements rely on the default.
     Rewrite `exports.foo()` calls to go through that object too, so `vi.spyOn()` on the default
     export also intercepts a module's calls to its own functions.
   - Translate `moduleNameMapper` into `resolve.alias`. Keep the same mappings for
     `ext.readingLists.*`, `codex.js`, `codex-icons`, `config.json`, `mediawiki.storage`, and
     `vue-router`.
   - Port `collectCoverageFrom`, `coveragePathIgnorePatterns`, and `coverageThreshold` into
     `test.coverage`, with the same numbers (statements 50, branches 44, functions 30, lines 50).
     Set `reporter` explicitly to `[ 'clover', 'json', 'lcov', 'text' ]`, because Vitest's default
     reporter set differs from Jest's, and CI expects a `coverage/lcov.info` file.
   - Set `test.setupFiles` to `[ './vitest.setup.js' ]`. Set `test.clearMocks` to `true`. Set
     `test.globals` to `true`.
3. **Port the setup file.** Port 'jest.setup.js' to 'vitest.setup.js'. Swap `jest.fn()` for
   `vi.fn()` in the global `mw` mock, and install it with `vi.stubGlobal()`. Have
   `mw.loader.require()` return modules imported through Vite, so they share the test files' module
   registry and count towards coverage. Also register the MediaWiki `v-i18n-html` Vue directive
   through `@vue/test-utils`'s `config.global.directives`, so mounted components resolve message
   content the same way production code does.
4. **Update test files and source.** Beyond the mechanical rename from `jest.*` to `vi.*` (`fn`,
   `mock`, `doMock`, `clearAllMocks`, `restoreAllMocks`, `useFakeTimers`, `advanceTimersByTime`,
   `useRealTimers`, `resetModules`), any test that needs mocking must load the module under test
   with `await import()`, not `require()`. The plugins from step 2 rewrite `require()` in
   'resources/' to ESM imports, so those modules live in Vite's module registry. A `require()` from
   a test goes through Node's loader instead, and returns a separate instance of the module, so
   `vi.mock()` or `stubApi()` applied to it never reaches the code under test. `await import()`
   goes through Vite, and returns the same instance the code under test uses.
   'resources/ext.readingLists.onboarding/mountApp.js' needs a small change too: it must call
   `vue.createMwApp()`, instead of destructuring `createMwApp` at import time, so the code reads
   the mocked `vue` module at call time.
5. **Regenerate snapshots.** Regenerate the '.snap' files and review the diffs. Vitest nests
   `describe` and `it` titles inside snapshot keys (for example, `"Foo > bar 1"`), where Jest
   flattened them (for example, `"Foo bar 1"`). So every snapshot changes shape, even where the
   rendered output stays the same. jsdom 30 also writes inline `url()` values quoted.
6. **Update scripts.** In 'package.json', change `test:unit` to `vitest run`. Change
   `test:unit:update-snapshots` to `npm run test:unit -- --update`. Change `coverage` to `npm run
   test:unit -- --coverage`; Vitest still needs the `--coverage` flag at invocation, even with
   `coverage.enabled: true` set in config, unlike Jest, where `collectCoverage: true` alone was
   enough. Keep the `test` script as `lint && test:unit`.
7. **Update lint config.** Add the Vitest global `vi`, plus `sourceType: module` and `ecmaVersion:
   2020`, to the ESLint config for `tests/**`. Disable the `es-x/no-dynamic-import` rule there,
   because the dynamic `import()` calls from step 4 only run in Node. Add a matching override in the
   root '.eslintrc.json' file for 'vitest.config.mjs' and 'vitest.setup.js', with `env: { es2020,
   node }` and the `vi` global, because these two files run in Node. For 'vitest.config.mjs', also
   disable `compat/compat`, `es-x/no-import-meta`, and `es-x/no-string-prototype-matchall`, because
   it uses `import.meta.dirname` and `String.prototype.matchAll()`.

## Consequences

- The Vue SFC transform moves to the maintained '@vitejs/plugin-vue' package, though two small
  custom Vite plugins in 'vitest.config.mjs' are still needed, to bridge MediaWiki's CJS-style
  `require()` and `module.exports` resources into the ESM world Vite expects.
- Test authorship stays close to unchanged (`describe`, `it`, `expect`, and
  '@vue/test-utils'), but the change is not a pure prefix rename. Some tests switch from `require()`
  to `await import()`, so mocking works under Vite's module runner, and one production file,
  'mountApp.js', needs a small source change too, so a mocked module reads correctly at call time.
- ReadingLists becomes an outlier that runs Vitest instead of Jest. If Wikimedia later standardizes
  on Jest more firmly, we may need to revisit this decision. If the ecosystem instead moves toward
  including Vite and Vitest, this extension already stays aligned.
- Coverage thresholds and behavior stay the same, so this change does not alter what we test. It
  only changes how tests are run.
- Test files stay in 'tests/jest/' for now, to keep the migration patch reviewable, and
  'tests/jest/.eslintrc.json' still sets `env: { jest: true }`. A follow-up renames the directory
  and drops the leftover Jest environment.

### References

- [Vitest documentation](https://vitest.dev/) — configuration, the `vi` API, and the Jest migration
  guide.
- [Vitest migration from Jest](https://vitest.dev/guide/migration.html#jest) — API-compatibility
  notes for `vi.*`, globals, and snapshots.
- [`@vitejs/plugin-vue`](https://github.com/vitejs/vite-plugin-vue) — the standard Vue 3 SFC
  compiler, used for both build and test.
- [Vue Test Utils](https://test-utils.vuejs.org/) — runner-agnostic component testing, unchanged by
  this migration.
