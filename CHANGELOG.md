# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/sebastiaanwouters/diffalyzer/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/sebastiaanwouters/diffalyzer/releases/tag/v1.0.0
