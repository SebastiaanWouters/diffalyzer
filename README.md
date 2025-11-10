# Diffalyzer

[![Latest Stable Version](https://poser.pugx.org/sebastiaanwouters/diffalyzer/v)](https://packagist.org/packages/sebastiaanwouters/diffalyzer)
[![Total Downloads](https://poser.pugx.org/sebastiaanwouters/diffalyzer/downloads)](https://packagist.org/packages/sebastiaanwouters/diffalyzer)
[![License](https://poser.pugx.org/sebastiaanwouters/diffalyzer/license)](https://packagist.org/packages/sebastiaanwouters/diffalyzer)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%20|%208.2%20|%208.3%20|%208.4-blue)](https://packagist.org/packages/sebastiaanwouters/diffalyzer)

A PHP CLI tool that analyzes git changes and outputs affected PHP file paths in formats compatible with PHPUnit, Psalm, ECS, and PHP-CS-Fixer. This enables optimized test and analysis runs by only processing files that are actually affected by changes.

## Features

- **Git Integration**: Analyze uncommitted, staged, or branch-based changes
- **Dependency Analysis**: Uses nikic/php-parser to build comprehensive dependency graphs
- **Multiple Strategies**: Choose between conservative, moderate, or minimal analysis depth
- **Format-Specific Output**: Tailored output for PHPUnit, Psalm, ECS, and PHP-CS-Fixer
- **Configurable Full Scans**: Define patterns in config file or CLI to trigger complete scans
- **Verbose Diagnostics**: Clear feedback about what's happening with --verbose mode
- **PSR-12 Compliant**: Clean, readable, and well-structured code

## Installation

### Via Composer (Recommended)

```bash
composer require --dev sebastiaanwouters/diffalyzer
```

After installation, the binary will be available at `vendor/bin/diffalyzer`.

### From Source

```bash
git clone https://github.com/sebastiaanwouters/diffalyzer.git
cd diffalyzer
composer install
```

## Quick Start

```bash
# Run only tests affected by your changes
vendor/bin/phpunit $(vendor/bin/diffalyzer --output test)

# Analyze only affected files with Psalm, ECS, or PHP-CS-Fixer
vendor/bin/psalm $(vendor/bin/diffalyzer --output files)
vendor/bin/ecs check $(vendor/bin/diffalyzer --output files)
vendor/bin/php-cs-fixer fix $(vendor/bin/diffalyzer --output files)
```

## Usage

### Basic Usage

```bash
# Get test files affected by uncommitted changes
vendor/bin/diffalyzer --output test

# Get all affected files (for Psalm, ECS, PHP-CS-Fixer, etc.)
vendor/bin/diffalyzer --output files
```

### With Strategies

```bash
# Conservative strategy (default): includes all dependencies
vendor/bin/diffalyzer --output test --strategy conservative

# Moderate strategy: excludes dynamic method calls
vendor/bin/diffalyzer --output test --strategy moderate

# Minimal strategy: only imports and direct inheritance
vendor/bin/diffalyzer --output test --strategy minimal
```

### Git Comparison Options

```bash
# Staged files only
vendor/bin/diffalyzer --output test --staged

# Compare branches
vendor/bin/diffalyzer --output test --from main --to feature-branch

# Compare commits
vendor/bin/diffalyzer --output test --from abc123 --to def456

# Compare from specific branch to HEAD
vendor/bin/diffalyzer --output test --from main
```

### Full Scan Pattern

Full-scan patterns can be configured via config file (recommended) or CLI flag:

**Via config file (recommended):**
```yaml
# diffalyzer.yml
full_scan_patterns:
  - "*.yml"
  - "config/**"
```

**Via CLI flag (overrides config):**
```bash
# Using regex patterns (must start with / or #)
vendor/bin/diffalyzer --output test --full-scan-pattern '/.*\.yml$/'
vendor/bin/diffalyzer --output test --full-scan-pattern '/^config\//'
vendor/bin/diffalyzer --output test --full-scan-pattern '/phpunit\.xml/'

# Using glob patterns (simpler, no / or # prefix needed)
vendor/bin/diffalyzer --output test --full-scan-pattern '*.xml'
vendor/bin/diffalyzer --output test --full-scan-pattern 'phpunit.xml'
vendor/bin/diffalyzer --output test --full-scan-pattern 'config/**'
```

**Note**: Always use `--verbose` to see:
- Which pattern is being used
- Which files changed
- Whether the pattern matched any files
- Whether full scan was triggered

## Integration Examples

### PHPUnit

```bash
# Run only affected tests
vendor/bin/phpunit $(vendor/bin/diffalyzer --output test)

# With configuration
vendor/bin/phpunit -c phpunit.xml $(vendor/bin/diffalyzer --output test)
```

### Psalm

```bash
# Analyze only affected files
vendor/bin/psalm $(vendor/bin/diffalyzer --output files)

# With specific error level
vendor/bin/psalm --show-info=false $(vendor/bin/diffalyzer --output files)
```

### ECS (Easy Coding Standard)

```bash
# Check only affected files
vendor/bin/ecs check $(vendor/bin/diffalyzer --output files)

# With fix
vendor/bin/ecs check --fix $(vendor/bin/diffalyzer --output files)
```

### PHP-CS-Fixer

```bash
# Fix only affected files
vendor/bin/php-cs-fixer fix $(vendor/bin/diffalyzer --output files)

# Dry run
vendor/bin/php-cs-fixer fix --dry-run $(vendor/bin/diffalyzer --output files)
```

## Command Line Options

| Option | Short | Description | Default |
|--------|-------|-------------|---------|
| `--output` | `-o` | Output format: `test` (test files only) or `files` (all files) | Required |
| `--strategy` | `-s` | Analysis strategy: `conservative`, `moderate`, `minimal` | `conservative` |
| `--from` | | Source ref for comparison (branch or commit hash) | |
| `--to` | | Target ref for comparison (branch or commit hash) | `HEAD` |
| `--staged` | | Only analyze staged files | `false` |
| `--full-scan-pattern` | | Regex (e.g., `/\.xml$/`) or glob pattern (e.g., `*.xml`) to trigger full scan (overrides config) | |
| `--config` | `-c` | Path to config file | Auto-detect |
| `--verbose` | `-v` | Show detailed diagnostic information (outputs to stderr) | `false` |
| `--test-pattern` | | Custom regex pattern to match test files | |
| `--no-cache` | | Disable cache and force full rebuild | `false` |
| `--clear-cache` | | Clear cache before analysis | `false` |
| `--cache-stats` | | Show cache statistics after analysis | `false` |
| `--parallel` | `-p` | Number of parallel workers for parsing | Auto-detect |

## Configuration File

Diffalyzer supports configuration files to centralize settings like full-scan patterns. Configuration files are automatically detected in the following order:

1. `.diffalyzer.yml`
2. `diffalyzer.yml`
3. `config.yml`

Or specify a custom path with `--config path/to/config.yml`.

### Example Configuration

```yaml
# diffalyzer.yml or .diffalyzer.yml
full_scan_patterns:
  # Dependency management files
  - composer.json
  - composer.lock
  - package.json

  # Configuration files (glob patterns)
  - "*.config.php"
  - "config/**"

  # Build files
  - Dockerfile
  - docker-compose.yml

  # Regex patterns (start with / or #)
  - "/\\.(env|config)$/"
```

### Pattern Types

**Glob patterns** (recommended for simplicity):
- `composer.json` - Exact filename match
- `*.json` - Any file ending with .json
- `config/**` - Any file in config directory (any depth)
- `src/*.php` - PHP files in src directory (one level)

**Regex patterns** (for advanced matching):
- Must start with `/` or `#`
- `/\.config\.(js|ts)$/` - Matches .config.js or .config.ts
- `/^(config|deploy)\//` - Matches files in config/ or deploy/ directories

### Complete Override Behavior

When you define patterns in your config file, they **completely replace** the built-in defaults:

- **With config file**: Only your patterns are used
- **Without config file**: Built-in defaults are used (`composer.json`, `composer.lock`)
- **With CLI `--full-scan-pattern`**: CLI always overrides everything (config + built-in)

**To disable full scans entirely:**
```yaml
full_scan_patterns: []
```

### Verbose Mode

Use `--verbose` (or `-v`) to see detailed diagnostic information about what diffalyzer is doing:

```bash
vendor/bin/diffalyzer --output test --verbose
```

Verbose output (sent to stderr, won't interfere with stdout):
```
[diffalyzer] Loaded 3 full-scan pattern(s) from config
[diffalyzer] Detected 5 changed file(s)
[diffalyzer] Analyzing 3 PHP file(s)...
[diffalyzer] Scanned project: found 247 PHP file(s) (0.12s)
[diffalyzer] Built dependency graph (0.45s)
[diffalyzer] Found 8 affected file(s)

[diffalyzer] Performance Metrics:
  Total time: 0.62s
  Scan time: 0.12s
  Parse time: 0.45s
  Changed files: 3
  Affected files: 8
```

Or when full scan is triggered:
```
[diffalyzer] Full scan triggered: "composer.json" matched pattern "composer.json"
```

This makes it much clearer what's happening, especially when you get empty output (which means "run on all files").

## Analysis Strategies

### Conservative (Default)
Includes all dependency types:
- Use statements (imports)
- Class inheritance (extends)
- Interface implementations
- Trait usage
- Instantiations (`new` keyword)
- Static calls

Most comprehensive but may include some false positives.

### Moderate
Includes:
- Use statements (imports)
- Class inheritance (extends)
- Interface implementations
- Trait usage

Excludes dynamic method calls and instantiations.

### Minimal
Includes only:
- Use statements (imports)
- Direct inheritance (extends/implements)

Fastest but may miss some affected files.

## Output Behavior

### Partial Scan (Normal Mode)

#### `--output test`
Outputs **space-separated test file paths** that are affected by the changes.

**How it works:**
The tool uses AST-based dependency analysis to find ALL test files that import or use the affected classes. It does NOT assume file naming conventions or directory structures.

**Examples:**

1. **Test files that import changed classes**
   - `src/User.php` changes (declares `Diffalyzer\User`)
   - `tests/UserTest.php` imports `Diffalyzer\User` → **included in output**
   - Works regardless of file names or directory structure

2. **Transitive dependencies**
   - `src/User.php` changes
   - `src/UserCollector.php` imports/uses `User` → affected
   - `tests/UserCollectorTest.php` imports `UserCollector` → **included in output**
   - Full dependency chain is traversed automatically

3. **Test files that changed directly**
   - `tests/UserTest.php` modified → **included in output**

4. **No assumptions about structure**
   - Works with `tests/`, `test/`, `Tests/`, `Test/`, or any directory
   - Works with any test file naming convention (as long as it contains "Test.php")
   - Works with custom project structures

5. **Data fixtures via PHP classes**
   - If tests use fixture/factory classes (e.g., `UserFixture`, `UserFactory`)
   - Changes to those fixture classes are tracked via normal dependency analysis
   - When `UserFixture.php` changes → tests importing it are included
   - No special configuration needed

Example output: `tests/UserTest.php tests/UserCollectorTest.php tests/Integration/UserFlowTest.php`

#### `--output files`
Outputs **space-separated file paths** for all affected files (includes both source and test files).

Use this for static analysis tools (Psalm), code style checkers (ECS, PHP-CS-Fixer), or any tool that needs to process all affected files.

Example output: `src/Foo/Bar.php src/Baz/Qux.php tests/FooTest.php`

### Full Scan Mode
When full-scan patterns match or no specific files are needed:
- All formats output an **empty string**
- Empty output tells each tool to scan the entire project
- Example: `phpunit` with no arguments runs all tests

**Full Scan Triggers:**

By default (when no config file is present), these files trigger full scans:
- `composer.json` - Dependencies changed, could affect anything
- `composer.lock` - Dependency versions changed

You can customize this behavior in three ways:

1. **Config file (recommended)**: Define patterns in `diffalyzer.yml` - replaces built-in defaults
2. **CLI flag**: Use `--full-scan-pattern` - overrides config and built-in defaults
3. **Disable entirely**: Set `full_scan_patterns: []` in config

**Understanding Empty Output:**

Empty output is **intentional** and can mean two things:
1. **No changes detected** - No PHP files were modified
2. **Full scan triggered** - A file matched a full-scan pattern

Use `--verbose` to see which one:
```bash
vendor/bin/diffalyzer --output test --verbose
# Output to stderr will show: "No changes detected" or "Full scan triggered: ..."
```

## How It Works

1. **Git Change Detection**: Detects changed PHP files using git diff
2. **AST Parsing**: Parses all project PHP files using nikic/php-parser
3. **Dependency Graph**: Builds forward and reverse dependency maps
4. **Impact Analysis**: Traverses graph to find all affected files
5. **Format Output**: Generates tool-specific output format

### Example Workflow

**Step 1: Git detects changes**
```
Changed: src/User.php
```

**Step 2: Dependency analysis**
```
User.php changed
    ↓
UserCollector.php uses User (affected)
    ↓
UserService.php uses UserCollector (affected)
```

**Step 3: Output by format**

For `--output files` (all source files):
```
src/User.php src/UserCollector.php src/UserService.php
```

For `--output test` (test files only):
```
tests/UserTest.php tests/UserCollectorTest.php tests/UserServiceTest.php
```

**Result**: Run only the 3 tests affected by the User.php change, not all 100+ tests in your suite!

## Advanced Integration

### Makefile

Use the included Makefile for convenient local development:

```bash
# Run affected tests
make test-affected

# Run tests for changes from main branch
make test-branch

# Analyze with Psalm
make psalm-affected

# Fix code style
make cs-fix-affected
make ecs-affected

# See all available targets
make help
```

### Pre-commit Hook

Create `.git/hooks/pre-commit`:

```bash
#!/bin/bash

# Run tests on staged files
TESTS=$(vendor/bin/diffalyzer --output test --staged)

if [ -n "$TESTS" ]; then
    echo "Running affected tests..."
    vendor/bin/phpunit $TESTS
    if [ $? -ne 0 ]; then
        echo "Tests failed. Commit aborted."
        exit 1
    fi
fi

exit 0
```

Make it executable:

```bash
chmod +x .git/hooks/pre-commit
```

### Composer Scripts

Add to your `composer.json`:

```json
{
    "scripts": {
        "test:affected": [
            "@php vendor/bin/phpunit $(vendor/bin/diffalyzer --output test)"
        ],
        "psalm:affected": [
            "@php vendor/bin/psalm $(vendor/bin/diffalyzer --output files)"
        ],
        "cs:fix:affected": [
            "@php vendor/bin/php-cs-fixer fix $(vendor/bin/diffalyzer --output files)"
        ]
    }
}
```

Then run:

```bash
composer test:affected
composer psalm:affected
composer cs:fix:affected
```

## CI/CD Integration

### GitHub Actions

See `.github/workflows/ci-example.yml` for a complete working example. Basic setup:

```yaml
name: Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0  # Full history for git diff
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.1
      - name: Install Dependencies
        run: composer install
      - name: Run Affected Tests
        run: |
          if [ "${{ github.event_name }}" == "pull_request" ]; then
            TESTS=$(vendor/bin/diffalyzer --output test --from origin/${{ github.base_ref }})
          else
            TESTS=$(vendor/bin/diffalyzer --output test)
          fi

          if [ -n "$TESTS" ]; then
            echo "Running affected tests: $TESTS"
            vendor/bin/phpunit $TESTS
          else
            echo "Running all tests (full scan triggered)"
            vendor/bin/phpunit
          fi
```

### GitLab CI

See `.gitlab-ci-example.yml` for a complete working example. Basic setup:

```yaml
variables:
  GIT_DEPTH: 0  # Fetch full git history

test:
  script:
    - composer install
    - |
      if [ -n "$CI_MERGE_REQUEST_TARGET_BRANCH_NAME" ]; then
        BASE_BRANCH="origin/$CI_MERGE_REQUEST_TARGET_BRANCH_NAME"
      else
        BASE_BRANCH="HEAD~1"
      fi

      TESTS=$(vendor/bin/diffalyzer --output test --from $BASE_BRANCH)

      if [ -n "$TESTS" ]; then
        vendor/bin/phpunit $TESTS
      else
        vendor/bin/phpunit
      fi
```

## Troubleshooting

### Full-scan pattern not working

**Problem**: Using `--full-scan-pattern` but full scan is not triggered.

**Solutions**:

1. **Use `--verbose` to debug**: This will show you:
   ```bash
   vendor/bin/diffalyzer --output test --full-scan-pattern '/test\.xml/' --verbose
   ```
   - Which files actually changed
   - Which pattern is being used
   - Whether the pattern matched
   - Whether full scan was triggered

2. **Check pattern syntax**:
   - **Regex patterns** must start with `/` or `#`: `/\.xml$/`, `/^config\//`
   - **Glob patterns** don't need delimiters: `*.xml`, `phpunit.xml`, `config/**`
   - When in doubt, use glob patterns (simpler and more intuitive)

3. **Verify the pattern matches your changed files**:
   ```bash
   # First see what files changed
   git status
   git diff --name-only

   # Then craft a pattern that matches them
   # If you changed "phpunit.xml":
   vendor/bin/diffalyzer --output test --full-scan-pattern 'phpunit.xml' --verbose
   # or
   vendor/bin/diffalyzer --output test --full-scan-pattern '/phpunit\.xml/' --verbose
   ```

4. **Common pattern examples**:
   ```bash
   # Match any XML file
   --full-scan-pattern '*.xml'
   --full-scan-pattern '/\.xml$/'

   # Match specific file anywhere
   --full-scan-pattern 'phpunit.xml'
   --full-scan-pattern '/phpunit\.xml/'

   # Match files in directory
   --full-scan-pattern 'config/**'
   --full-scan-pattern '/^config\//'

   # Match multiple extensions (regex only)
   --full-scan-pattern '/(\.xml|\.yml)$/'
   ```

5. **Pattern doesn't match subdirectories?**
   - For glob: use `config/**` not `config/*` for recursive matching
   - For regex: use `/^config\//` to match anything starting with `config/`

### No tests run but I made changes

**Cause**: You changed a file that no tests depend on.

**Solutions**:
- Ensure your test files import the classes they test
- Try conservative strategy: `--strategy conservative`
- Verify your changes are to tracked files (not untracked/ignored)
- Check dependency chain: does a test import your changed class?

### All tests run when I change one file

**Cause**: Full scan was triggered.

**Solutions**:
- Check if you modified `composer.json` or `composer.lock`
- Check if your change matches `--full-scan-pattern`
- Review changed files: `git status`
- This is by design for critical files

### Git errors

**Cause**: Not in a git repository or no commits exist.

**Solutions**:
- Ensure you're in a git repository: `git init`
- Create an initial commit: `git add . && git commit -m "Initial commit"`
- Verify git is accessible: `git --version`

### Empty output

This is **normal behavior** and means:
- **For PHPUnit**: No test files affected OR full scan triggered → run all tests
- **For other tools**: No files affected OR full scan triggered → analyze all files

Always handle empty output by running the full tool:

```bash
TESTS=$(vendor/bin/diffalyzer --output test)
if [ -n "$TESTS" ]; then
    vendor/bin/phpunit $TESTS
else
    vendor/bin/phpunit  # Full run
fi
```

## Tips & Best Practices

1. **Use Makefile**: Convenient shortcuts for common operations
2. **CI/CD Branch Comparison**: Use `--from origin/main` in pipelines
3. **Start Conservative**: Begin with conservative strategy, optimize later if needed
4. **Fixture Classes**: Modern fixtures (PHP classes) are automatically tracked
5. **Pre-commit Hooks**: Use `--staged` flag for pre-commit validation
6. **Full Scan Patterns**: Add critical config files to trigger complete scans
7. **Test Imports**: Ensure test files import the classes they test for proper tracking
8. **Git History**: Use `fetch-depth: 0` in CI to enable proper branch comparison

## Requirements

### PHP Version Support

Diffalyzer supports the following PHP versions:

| PHP Version | Status | Tested |
|-------------|--------|--------|
| 8.1         | ✅ Supported | ✅ Yes |
| 8.2         | ✅ Supported | ✅ Yes |
| 8.3         | ✅ Supported | ✅ Yes |
| 8.4         | ✅ Supported | ✅ Yes |
| 8.0 or lower | ❌ Not supported | - |

All versions are actively tested in CI/CD with both `prefer-lowest` and `prefer-stable` dependency strategies.

### Other Requirements

- **Git**: Any recent version
- **Composer**: 2.0 or higher

## Dependencies

- `nikic/php-parser` ^5.0
- `symfony/console` ^6.4 || ^7.0
- `symfony/process` ^6.4 || ^7.0
- `symfony/finder` ^6.4 || ^7.0
- `symfony/yaml` ^6.4 || ^7.0

## License

MIT

## Author

Created by [Sebastiaan Wouters](https://github.com/sebastiaanwouters) for optimized PHP testing and analysis workflows.
