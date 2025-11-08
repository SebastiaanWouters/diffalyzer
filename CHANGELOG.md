# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.4.0...HEAD
[1.4.0]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/sebastiaanwouters/diffalyzer/releases/tag/v1.0.0
