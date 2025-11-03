# Diffalyzer - Real World Examples

## Example 1: Single File Change

### Scenario
You modify `src/User.php` to add a new method.

### Command
```bash
php bin/diffalyzer --output phpunit
```

### Output
```
tests/UserTest.php tests/UserCollectorTest.php
```

### Explanation
- `tests/UserTest.php` - Direct test for User.php
- `tests/UserCollectorTest.php` - UserCollector depends on User, so its test must run

### CI Integration
```bash
vendor/bin/phpunit tests/UserTest.php tests/UserCollectorTest.php
```

---

## Example 2: Test File Modified

### Scenario
You add a new test method to `tests/UserTest.php`.

### Command
```bash
php bin/diffalyzer --output phpunit
```

### Output
```
tests/UserTest.php
```

### Explanation
Only the modified test file needs to run.

---

## Example 3: Configuration File Change

### Scenario
You modify `config/database.yml` and want all tests to run.

### Command
```bash
php bin/diffalyzer --output phpunit --full-scan-pattern '/config\/.*\.yml$/'
```

### Output
```
(empty string)
```

### Explanation
Empty output means run the full test suite. Use it like:
```bash
TESTS=$(php bin/diffalyzer --output phpunit --full-scan-pattern '/config\/.*\.yml$/')
if [ -z "$TESTS" ]; then
  vendor/bin/phpunit  # Run all tests
else
  vendor/bin/phpunit $TESTS  # Run specific tests
fi
```

---

## Example 4: Branch Comparison

### Scenario
You're on a feature branch and want to see what tests to run compared to main.

### Command
```bash
php bin/diffalyzer --output phpunit --from origin/main
```

### Output
```
tests/UserTest.php tests/UserCollectorTest.php tests/OrderTest.php
```

### Explanation
All tests affected by changes in your branch vs main.

---

## Example 5: Multiple Output Formats

### For Static Analysis (Psalm)
```bash
vendor/bin/psalm $(php bin/diffalyzer --output psalm --from origin/main)
```

### For Code Style (ECS)
```bash
vendor/bin/ecs check $(php bin/diffalyzer --output ecs --from origin/main)
```

### For Code Fixing (CS-Fixer)
```bash
vendor/bin/php-cs-fixer fix $(php bin/diffalyzer --output cs-fixer)
```

---

## Example 6: Different Strategies

### Conservative (Default) - Most Thorough
```bash
php bin/diffalyzer --output phpunit --strategy conservative
```
Includes all dependencies: imports, inheritance, instantiations, static calls

### Moderate - Balanced
```bash
php bin/diffalyzer --output phpunit --strategy moderate
```
Excludes dynamic method calls, focuses on static dependencies

### Minimal - Fastest
```bash
php bin/diffalyzer --output phpunit --strategy minimal
```
Only direct imports and inheritance

---

## Example 7: Complex Dependency Chain

### Files
```
src/Model/User.php
src/Repository/UserRepository.php (uses User)
src/Service/UserService.php (uses UserRepository)
src/Controller/UserController.php (uses UserService)
```

### Change
Modify `src/Model/User.php`

### Output
```bash
php bin/diffalyzer --output phpunit
```
```
tests/Model/UserTest.php
tests/Repository/UserRepositoryTest.php
tests/Service/UserServiceTest.php
tests/Controller/UserControllerTest.php
```

### Explanation
The entire dependency chain is analyzed, and all affected tests are included!

---

## Example 8: CI/CD Pipeline Integration

### GitHub Actions Workflow
```yaml
name: Run Affected Tests
on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
        with:
          fetch-depth: 0  # Important for git diff
          
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.1
          
      - name: Install Dependencies
        run: composer install
        
      - name: Run Affected Tests
        run: |
          TESTS=$(php bin/diffalyzer --output phpunit --from origin/main)
          if [ -z "$TESTS" ]; then
            echo "Running full test suite"
            vendor/bin/phpunit
          else
            echo "Running affected tests: $TESTS"
            vendor/bin/phpunit $TESTS
          fi
```

### Benefits
- ⚡ Faster CI builds (run only affected tests)
- 💰 Reduced CI costs (less compute time)
- 🎯 Focused feedback (know exactly what broke)

---

## Example 9: Composer Dependencies Change (Automatic)

### Scenario
You update `composer.json` or `composer.lock`.

### Command
```bash
php bin/diffalyzer --output phpunit
```

### Output
```
(empty string - run all tests)
```

### Explanation
Composer file changes **automatically** trigger a full scan (built-in feature).
No `--full-scan-pattern` needed! Dependency changes can affect anything.

---

## Example 10: Multiple Patterns

### Scenario
Trigger full scan for config files OR composer files.

### Command
```bash
php bin/diffalyzer --output phpunit \
  --full-scan-pattern '/(config\/.*\.(yml|yaml|php)|composer\.(json|lock))/'
```

### Matches
- `config/app.yml` ✅
- `config/database.yaml` ✅
- `config/services.php` ✅
- `composer.json` ✅
- `composer.lock` ✅
- `src/User.php` ❌

---

## Performance Comparison

### Before Diffalyzer
```bash
# Always run all tests
vendor/bin/phpunit
# Time: 5 minutes for 500 tests
```

### After Diffalyzer
```bash
# Run only affected tests
vendor/bin/phpunit $(php bin/diffalyzer --output phpunit --from origin/main)
# Time: 30 seconds for 15 affected tests
```

### Savings
- **90% faster** CI builds
- **10x** more iterations per day
- Faster feedback loop for developers


---

## Example 11: Data Fixtures via PHP Classes

### Scenario
You have fixture classes that provide test data, and tests import them.

### File Structure
```
src/Fixtures/UserFixture.php  (provides User objects for testing)
tests/UserServiceTest.php      (imports UserFixture)
tests/UserRepositoryTest.php   (imports UserFixture)
```

### UserFixture.php
```php
<?php
namespace App\Fixtures;

use App\User;

class UserFixture
{
    public static function createTestUser(): User
    {
        return new User('Test User', 'test@example.com');
    }
}
```

### Tests using the fixture
```php
use App\Fixtures\UserFixture;

class UserServiceTest
{
    public function testSomething(): void
    {
        $user = UserFixture::createTestUser();
        // test logic...
    }
}
```

### When UserFixture.php Changes

```bash
php bin/diffalyzer --output phpunit
```

### Output
```
tests/UserServiceTest.php tests/UserRepositoryTest.php
```

### Explanation
Both tests import `UserFixture`, so they're included via normal AST dependency analysis.
No special fixture tracking needed - it works like any other PHP class dependency!

---
