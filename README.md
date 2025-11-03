# Diffalyzer

A PHP CLI tool that analyzes git changes and outputs affected PHP file paths in formats compatible with PHPUnit, Psalm, ECS, and PHP-CS-Fixer. This enables optimized test and analysis runs by only processing files that are actually affected by changes.

## Features

- **Git Integration**: Analyze uncommitted, staged, or branch-based changes
- **Dependency Analysis**: Uses nikic/php-parser to build comprehensive dependency graphs
- **Multiple Strategies**: Choose between conservative, moderate, or minimal analysis depth
- **Format-Specific Output**: Tailored output for PHPUnit, Psalm, ECS, and PHP-CS-Fixer
- **Full Scan Triggers**: Regex patterns to force complete project scans for critical files
- **PSR-12 Compliant**: Clean, readable, and well-structured code

## Installation

```bash
composer require diffalyzer/diffalyzer
```

Or for development:

```bash
git clone https://github.com/yourusername/diffalyzer.git
cd diffalyzer
composer install
```

## Usage

### Basic Usage

```bash
# Analyze uncommitted changes for PHPUnit
php bin/diffalyzer --output phpunit

# Analyze for Psalm
php bin/diffalyzer --output psalm

# Analyze for ECS
php bin/diffalyzer --output ecs

# Analyze for PHP-CS-Fixer
php bin/diffalyzer --output cs-fixer
```

### With Strategies

```bash
# Conservative strategy (default): includes all dependencies
php bin/diffalyzer --output phpunit --strategy conservative

# Moderate strategy: excludes dynamic method calls
php bin/diffalyzer --output phpunit --strategy moderate

# Minimal strategy: only imports and direct inheritance
php bin/diffalyzer --output phpunit --strategy minimal
```

### Git Comparison Options

```bash
# Staged files only
php bin/diffalyzer --output phpunit --staged

# Compare branches
php bin/diffalyzer --output phpunit --from main --to feature-branch

# Compare commits
php bin/diffalyzer --output phpunit --from abc123 --to def456

# Compare from specific branch to HEAD
php bin/diffalyzer --output phpunit --from main
```

### Full Scan Pattern

```bash
# Trigger full scan if any .yml file changes
php bin/diffalyzer --output phpunit --full-scan-pattern '/.*\.yml$/'

# Trigger full scan for config or composer changes
php bin/diffalyzer --output phpunit --full-scan-pattern '/(config\/.*\.php|composer\.(json|lock))/'
```

## Integration Examples

### PHPUnit

```bash
# Run only affected tests
vendor/bin/phpunit $(php bin/diffalyzer --output phpunit)

# With configuration
vendor/bin/phpunit -c phpunit.xml $(php bin/diffalyzer --output phpunit)
```

### Psalm

```bash
# Analyze only affected files
vendor/bin/psalm $(php bin/diffalyzer --output psalm)

# With specific error level
vendor/bin/psalm --show-info=false $(php bin/diffalyzer --output psalm)
```

### ECS (Easy Coding Standard)

```bash
# Check only affected files
vendor/bin/ecs check $(php bin/diffalyzer --output ecs)

# With fix
vendor/bin/ecs check --fix $(php bin/diffalyzer --output ecs)
```

### PHP-CS-Fixer

```bash
# Fix only affected files
vendor/bin/php-cs-fixer fix $(php bin/diffalyzer --output cs-fixer)

# Dry run
vendor/bin/php-cs-fixer fix --dry-run $(php bin/diffalyzer --output cs-fixer)
```

## Command Line Options

| Option | Short | Description | Default |
|--------|-------|-------------|---------|
| `--output` | `-o` | Output format: `phpunit`, `psalm`, `ecs`, `cs-fixer` | Required |
| `--strategy` | `-s` | Analysis strategy: `conservative`, `moderate`, `minimal` | `conservative` |
| `--from` | | Source ref for comparison (branch or commit hash) | |
| `--to` | | Target ref for comparison (branch or commit hash) | `HEAD` |
| `--staged` | | Only analyze staged files | `false` |
| `--full-scan-pattern` | | Regex pattern to trigger full scan | |

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

#### PHPUnit Output
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

Example output: `tests/UserTest.php tests/UserCollectorTest.php tests/Integration/UserFlowTest.php`

#### Other Formats (Psalm/ECS/PHP-CS-Fixer)
Outputs **space-separated source file paths** (includes both source and test files):
- Example: `src/Foo/Bar.php src/Baz/Qux.php tests/FooTest.php`

### Full Scan Mode
When `--full-scan-pattern` matches or no specific files are needed:
- All formats output an **empty string**
- Empty output tells each tool to scan the entire project
- Example: `phpunit` with no arguments runs all tests

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

For Psalm/ECS/CS-Fixer (all source files):
```
src/User.php src/UserCollector.php src/UserService.php
```

For PHPUnit (test files only):
```
tests/UserTest.php tests/UserCollectorTest.php tests/UserServiceTest.php
```

**Result**: Run only the 3 tests affected by the User.php change, not all 100+ tests in your suite!

## CI/CD Integration

### GitHub Actions

```yaml
name: Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
        with:
          fetch-depth: 0
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.1
      - name: Install Dependencies
        run: composer install
      - name: Run Affected Tests
        run: |
          TESTS=$(php bin/diffalyzer --output phpunit --from origin/main)
          if [ -n "$TESTS" ]; then
            vendor/bin/phpunit $TESTS
          else
            vendor/bin/phpunit
          fi
```

### GitLab CI

```yaml
test:
  script:
    - composer install
    - TESTS=$(php bin/diffalyzer --output phpunit --from origin/main)
    - |
      if [ -n "$TESTS" ]; then
        vendor/bin/phpunit $TESTS
      else
        vendor/bin/phpunit
      fi
```

## Requirements

- PHP >= 8.1
- Git repository
- Composer

## Dependencies

- `nikic/php-parser` ^5.0
- `symfony/console` ^7.0
- `symfony/process` ^7.0
- `symfony/finder` ^7.0

## License

MIT

## Contributing

Contributions are welcome! Please ensure:
- PSR-12 compliance
- Comprehensive tests
- Updated documentation

## Author

Created with ❤️ for optimized PHP testing and analysis workflows.
