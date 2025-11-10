# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.7.3] - 2025-11-10

### Fixed
- **Critical bug**: Method-level output with `--filter` was unreliable with PHPUnit
  - v1.7.2 attempted to use `--filter '/method1|method2|...'/'` syntax with multiple files
  - This caused PHPUnit to skip test files that didn't contain all the filtered method names
  - Example: `tests/FileA.php tests/FileB.php --filter '/testA|testB/'` would only run tests from files containing both methods
  - Now `formatMethods()` outputs only the test file paths without method filtering
  - This ensures all relevant test files run completely, trading method granularity for reliability
  - Method-level dependency analysis still determines which files to run (this is the key value)

## [1.7.2] - 2025-11-10

### Fixed
- **Critical bug**: `formatMethods()` was outputting invalid PHPUnit syntax (file::method pairs)
  - Now properly converts method-level output to PHPUnit's `--filter` argument format
  - Example: `tests/UserTest.php tests/FooTest.php --filter '/testLogin|testLogout|testBar/'`
  - Previously output: `tests/UserTest.php::testLogin tests/UserTest.php::testLogout tests/FooTest.php::testBar`
  - PHPUnit does not support `::` syntax; method filtering requires `--filter` with the filename
  - **Note**: This approach was found to be unreliable and fixed in v1.7.3

## [1.7.1] - 2025-01-10

### Added
- Support for `::` syntax to specify individual test methods (e.g., `tests/UserTest.php::testLogin`)
- Automatic conversion from `::` syntax to PHPUnit's `--filter` argument
- Smart handling of multiple test methods with regex filter combination

### Changed
- `PhpUnitFormatter` now parses `::methodName` syntax in file paths
- Test method names with special regex characters are automatically escaped
- Fixed `isTestFile()` to properly handle `::` syntax in file paths by stripping method names before pattern matching

## [1.7.0] - 2025-11-10

### Fixed
- **Critical bug**: Changed files were missing from Psalm/PHPStan output when using method-level granularity
  - Files containing changed methods are now always included in the affected files output
  - Fixed issue where `classToFileMap` was empty, causing class name resolution to fail
  - Moved `getClassToFileMap()` call to after `buildDependencyGraph()` when the map is populated

- **Critical bug**: PHPUnit output showed 0 tests even when relevant tests existed
  - Short class names from `MethodChangeParser` are now correctly mapped to fully qualified names
  - Added cascading test matching strategy: direct method calls → class-level matching → namespace matching
  - Implemented `findTestMethodsForClasses()` to find tests calling any method on changed classes
  - Implemented `findTestMethodsByNamespace()` as fallback using path/namespace heuristics

### Added
- **Smart test discovery**: Three-level cascading approach for finding relevant tests:
  1. Direct method call matching (most precise)
  2. Class-level matching (tests using any method on changed classes)
  3. Namespace/directory matching (tests in same bundle/namespace as changed files)
- New helper method `mapShortNamesToFQN()` to convert short class names to fully qualified names
- New helper method `extractClassesFromMethods()` to extract class names from method signatures

### Changed
- Psalm/PHPStan formatters now always use file-level output (method-level output not meaningful for static analyzers)
- PHPUnit formatter only uses method-level output when appropriate (test discovery)
- Improved verbose output showing the test matching strategy used
- Better diagnostic messages when falling back through test matching strategies

### Improved
- Test discovery is now more robust and finds tests even when they don't directly call changed methods
- Reduced false negatives where relevant tests were not detected
- Better handling of projects with namespace-based directory structures

## [1.6.2] - 2025-11-10

### Improved
- **Full-scan pattern functionality** with glob pattern support and enhanced diagnostics
  - Added support for glob patterns (e.g., `*.xml`, `phpunit.xml`, `config/**`) alongside regex patterns
  - Glob patterns don't require delimiters, making them simpler and more intuitive to use
  - Enhanced `--verbose` output to show which files changed and whether patterns matched
  - Improved pattern validation with clearer error messages and helpful examples
  - Added warning when CLI pattern is provided but doesn't match any changed files
  - Better documentation with comprehensive troubleshooting section for pattern usage

### Added
- Comprehensive pattern usage examples in README for both glob and regex patterns
- Troubleshooting section specifically for full-scan pattern issues
- New test cases for glob pattern matching and CLI pattern override behavior
- Helpful feedback when patterns don't match, guiding users to correct syntax

### Changed
- Pattern validation now distinguishes between regex patterns (starting with `/` or `#`) and glob patterns
- Verbose mode now lists all changed files to help with pattern debugging
- Error messages now include pattern syntax examples and recommendations

## [1.6.1] - 2025-11-10

### Added
- **PHPStan Output Format**: New `--output=phpstan` option for PHPStan integration
- Empty output diagnostics to help users understand why no output is generated
- Diagnostic messages for different scenarios:
  - "No test files found in affected files" (PHPUnit mode)
  - "Files changed but no dependencies affected" (isolated changes)
  - "No affected methods detected" (method-level analysis)

### Fixed
- **Critical bug**: `formatMethods()` was never called, preventing method-level output from working
- Method-level analysis now correctly outputs method names instead of just files
- PHPUnit, Psalm, and PHPStan formatters now properly support method-level granularity

### Improved
- Error messages now show which format was invalid and list all supported formats
- Help text updated to mention phpstan format
- Diagnostics include helpful hints for troubleshooting

## [1.6.0] - 2025-11-10

### Added - Major Release 🎯
- **Method-Level Granularity**: Revolutionary precision for test targeting
  - Method-level change detection using git diff analysis
  - Method call graph construction for dependency tracking
  - Test method analysis to map tests to production code
  - Identifies exact test methods that exercise changed code instead of entire files
  - Backward compatible with `--file-level` flag to revert to file-level analysis

### New Components
- `TestMethodAnalyzer` for test method identification
- `MethodCallExtractor` for method-level call graph construction
- `MethodChangeParser` for git diff method tracking
- `MethodAwareFormatterInterface` for method output support
- Enhanced formatters (PHPUnit, Psalm) with method-to-file mapping

### Configuration
- Method-level granularity enabled by default
- `--method-level` flag to explicitly enable (default behavior)
- `--file-level` flag to revert to file-level granularity
- Updated config.yml with comprehensive documentation

### Performance
- More precise test targeting reduces test execution overhead
- Minimal performance impact compared to file-level analysis
- Efficient method call graph construction using token-based parsing

## [1.5.0] - 2025-11-08

### Performance - Major Release 🚀
- **Token-Based Parser**: Revolutionary 5-10x faster parsing using PHP's built-in `token_get_all()` instead of AST
  - New default parser provides 500-1000% speedup for cold starts
  - Maintains 100% accuracy and backward compatibility
  - AST parser still available as fallback option
  - Created comprehensive parser architecture with `ParserInterface`, `TokenBasedParser`, `AstBasedParser`
  - 600+ line `TokenBasedDependencyExtractor` handles complex PHP features correctly
  - Verified with 12 comprehensive comparison tests ensuring identical results

- **Algorithmic Optimizations**: Critical improvements to core algorithms
  - Fixed O(n²) → O(n) change detection in `FileHashRegistry` (50-90% faster for large codebases)
  - Fixed O(m×n) → O(k) class cleanup in incremental mode (80-95% faster)
  - Added bidirectional `fileToClassesMap` index for O(1) class lookups
  - Optimized `CacheInvalidator` with O(1) lookups (90%+ faster)
  - All strategies updated to support new `ParseResult` interface

### Added
- `src/Parser/ParserInterface.php` - Common interface for parsers
- `src/Parser/ParseResult.php` - Value object for parse results
- `src/Parser/TokenBasedParser.php` - Fast token-based parser adapter (default)
- `src/Parser/AstBasedParser.php` - AST parser adapter for compatibility
- `src/Parser/TokenBasedDependencyExtractor.php` - Core token parsing engine
- `tests/Parser/ParserComparisonTest.php` - Comprehensive correctness tests (12 tests)
- `PERFORMANCE_IMPROVEMENTS.md` - Detailed performance optimization documentation
- Parser type configuration support in `DependencyAnalyzer` constructor

### Changed
- **Default parser is now token-based** for maximum performance
- `DependencyAnalyzer` accepts optional parser type parameter (`'token'` or `'ast'`)
- All strategies implement new `extractDependenciesFromResult()` method
- `StrategyInterface` extended with `ParseResult` support while maintaining backward compatibility
- Cache now stores `fileToClassesMap` for optimized lookups

### Performance Benchmarks
- **Real-world (41 files)**: Parse time 60ms → 10ms (6x faster)
- **Synthetic (100 classes)**: 2.7ms → 1.7ms (1.6x faster)
- **Scalability (1000 files)**:
  - Change detection: 1,000,000 ops → 1,000 ops (99.9% reduction)
  - Class cleanup: 10,000 ops → 10 ops per file (99% reduction)
  - Parsing: 500ms → 50ms (10x faster)
- **Combined improvements**: 6-8x faster overall cold start performance

### Technical Details
- Token parser correctly handles: namespaces, use statements (including group use), aliases, class/interface/trait declarations, extends, implements, trait usage, new instantiations, static calls, fully qualified names
- All 109 existing tests pass + 12 new parser comparison tests
- Zero breaking changes - 100% backward compatible
- Clean architecture following DRY principles with comprehensive documentation

## [1.4.0] - 2025-11-08

### Added
- **Configuration File Support**: Define full-scan patterns in YAML config files
  - Auto-detects `.diffalyzer.yml`, `diffalyzer.yml`, or `config.yml`
  - Custom config path via `--config` flag
  - Complete override behavior: config patterns replace built-in defaults
  - Support for both glob patterns (`*.json`, `config/**`) and regex patterns (`/\.env$/`)
  - Can disable all full scans with `full_scan_patterns: []`
- **Verbose Mode**: Detailed diagnostic output via `-v` or `--verbose` flag
  - Shows which config file was loaded and how many patterns
  - Displays when full scan is triggered and which file/pattern matched
  - Outputs to stderr to avoid interfering with tool integration
  - Shows performance metrics and analysis statistics
  - Clear feedback when no changes are detected
- Added `symfony/yaml` dependency for config file parsing
- New `ConfigLoader` class for loading and parsing configuration files
- Enhanced `FullScanMatcher` with glob pattern support and pattern tracking

### Changed
- Full-scan patterns now configurable via YAML instead of being hardcoded
- CLI `--full-scan-pattern` now completely overrides config patterns (not additive)
- `FullScanMatcher` now accepts optional config patterns in constructor
- Empty output behavior is now clearer with verbose mode explaining the reason
- Built-in patterns (composer.json, composer.lock) only used when no config file exists

### Improved
- Better user experience with clear diagnostic messages about what diffalyzer is doing
- More flexible configuration allowing per-project customization
- Easier to disable built-in full-scan triggers when not needed

## [1.3.0] - 2025-11-08

### Performance
- **Major performance optimizations** providing 3-15x speedup depending on cache state and project size:
  - Removed wasteful MD5 hash computation in `FileHashRegistry` (30-50% faster cache operations)
  - Optimized JSON serialization by removing `JSON_PRETTY_PRINT` from cache files (20-30% faster cache I/O, 30-40% smaller files)
  - Eliminated redundant `array_unique` calls using associative array patterns (15-25% faster)
  - Optimized strategy dependency extraction with O(1) insertion instead of O(n) uniqueness checks
  - Integrated `ParallelParser` for projects with 100+ files (2-8x faster on multi-core systems)
- Benchmark results on 41-file project: warm cache 6.54ms (14.6x faster), cold cache 95.30ms

### Added
- Enhanced error handling for parallel parser
- Improved cache validation logic

### Changed
- Automatic parallel parsing enabled for projects with 100+ files
- Smart detection of vendor/autoload.php availability to prevent issues in test environments

## [1.2.0] - 2025-11-04

### Added
- **Caching System**: Intelligent caching with file hash tracking for faster subsequent analysis
  - `CacheManager` for managing cached dependency graphs
  - `FileHashRegistry` for tracking file changes
  - `CacheInvalidator` for selective cache invalidation
- **Parallel Parsing**: Multi-threaded AST parsing for improved performance on large codebases
- **.gitignore Support**: Automatic filtering of ignored files using `GitignoreFilter`
- Incremental analysis support - only re-parse changed files when cache is available

### Changed
- `DependencyAnalyzer` now supports caching with automatic invalidation
- Cache can be disabled with `setCacheEnabled(false)` method
- Updated `.gitignore` to exclude `.diffalyzer/` cache directory

### Performance
- Significantly faster analysis on subsequent runs with caching enabled
- Parallel parsing reduces initial analysis time for large projects

## [1.1.0] - 2025-11-03

### Added
- `--test-pattern` flag to customize test file matching with custom regex patterns
- Support for non-standard test file naming conventions (e.g., *Spec.php, *Integration.php)
- Comprehensive test coverage for custom test pattern functionality

### Changed
- Custom test pattern overrides default behavior when provided
- Improved error message specificity for `--full-scan-pattern` validation

## [1.0.1] - 2025-11-03

### Fixed
- PHP 8.1 compatibility by supporting both Symfony 6.4+ and 7.x
- PHP 8.1 compatibility by supporting both PHPUnit 10.5+ and 11.x
- Removed redundant CI workflow that was causing confusion

### Changed
- Updated dependency constraints to support wider PHP version range
- Simplified CI/CD workflows

## [1.0.0] - 2025-11-03

### Added
- Initial release of Diffalyzer
- AST-based PHP dependency analysis using nikic/php-parser
- Git integration for detecting changed files (uncommitted, staged, branch comparisons)
- Three analysis strategies: conservative, moderate, minimal
- Two output formats:
  - `--output test` for PHPUnit (test files only)
  - `--output files` for Psalm, ECS, PHP-CS-Fixer (all affected files)
- Full scan triggers for critical files (composer.json, composer.lock)
- Custom full scan pattern support via regex
- Comprehensive test suite with 103 tests and 179 assertions
- PSR-12 compliant code
- CI/CD integration examples for GitHub Actions and GitLab CI
- Makefile with convenient shortcuts
- Complete documentation in README.md

### Features
- **Dependency Graph Building**: Forward and reverse dependency mapping
- **Transitive Dependency Analysis**: Follows full dependency chains
- **Multiple Strategies**: Choose analysis depth based on needs
- **Format-Specific Output**: Tailored for different PHP tools
- **Vendor Directory Exclusion**: Automatically excludes vendor and other common directories
- **VCS Integration**: Respects .gitignore patterns
- **Test File Detection**: AST-based, works with any directory structure
- **Fixture Class Tracking**: Automatically tracks PHP fixture/factory classes

### Supported Tools
- PHPUnit (test execution)
- Psalm (static analysis)
- Easy Coding Standard (ECS)
- PHP-CS-Fixer (code style)

### Requirements
- PHP 8.1, 8.2, 8.3, or 8.4
- Git repository
- Composer 2.0+

[Unreleased]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.7.1...HEAD
[1.7.1]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.7.0...v1.7.1
[1.7.0]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.6.2...v1.7.0
[1.6.2]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.6.1...v1.6.2
[1.6.1]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.6.0...v1.6.1
[1.6.0]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/sebastiaanwouters/diffalyzer/releases/tag/v1.0.0
