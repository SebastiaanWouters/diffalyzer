.PHONY: help install test test-affected psalm psalm-affected cs-fix cs-fix-affected ecs ecs-affected

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

install: ## Install dependencies
	composer install

test: ## Run all tests
	vendor/bin/phpunit

test-affected: ## Run only tests affected by uncommitted changes
	@TESTS=$$(vendor/bin/diffalyzer --output test); \
	if [ -n "$$TESTS" ]; then \
		echo "Running affected tests: $$TESTS"; \
		vendor/bin/phpunit $$TESTS; \
	else \
		echo "No affected tests or full scan triggered"; \
		vendor/bin/phpunit; \
	fi

test-branch: ## Run tests affected by changes from main branch (usage: make test-branch)
	@TESTS=$$(vendor/bin/diffalyzer --output test --from main); \
	if [ -n "$$TESTS" ]; then \
		echo "Running affected tests: $$TESTS"; \
		vendor/bin/phpunit $$TESTS; \
	else \
		echo "No affected tests or full scan triggered"; \
		vendor/bin/phpunit; \
	fi

psalm: ## Run Psalm on all files
	vendor/bin/psalm

psalm-affected: ## Run Psalm on affected files only
	@FILES=$$(vendor/bin/diffalyzer --output files); \
	if [ -n "$$FILES" ]; then \
		echo "Analyzing affected files: $$FILES"; \
		vendor/bin/psalm $$FILES; \
	else \
		echo "No affected files or full scan triggered"; \
		vendor/bin/psalm; \
	fi

cs-fix: ## Fix code style on all files
	vendor/bin/php-cs-fixer fix

cs-fix-affected: ## Fix code style on affected files only
	@FILES=$$(vendor/bin/diffalyzer --output files); \
	if [ -n "$$FILES" ]; then \
		echo "Fixing affected files: $$FILES"; \
		vendor/bin/php-cs-fixer fix $$FILES; \
	else \
		echo "No affected files or full scan triggered"; \
		vendor/bin/php-cs-fixer fix; \
	fi

ecs: ## Check code style with ECS on all files
	vendor/bin/ecs check

ecs-affected: ## Check code style with ECS on affected files only
	@FILES=$$(vendor/bin/diffalyzer --output files); \
	if [ -n "$$FILES" ]; then \
		echo "Checking affected files: $$FILES"; \
		vendor/bin/ecs check $$FILES; \
	else \
		echo "No affected files or full scan triggered"; \
		vendor/bin/ecs check; \
	fi

ecs-fix-affected: ## Fix code style with ECS on affected files only
	@FILES=$$(vendor/bin/diffalyzer --output files); \
	if [ -n "$$FILES" ]; then \
		echo "Fixing affected files: $$FILES"; \
		vendor/bin/ecs check --fix $$FILES; \
	else \
		echo "No affected files or full scan triggered"; \
		vendor/bin/ecs check --fix; \
	fi
