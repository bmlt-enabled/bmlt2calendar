
.PHONY: lint
lint:  ## PHP Lint
	composer -q install
	find . -name "*.php" ! -path '*/vendor/*' -print0 | xargs -0 -n1 -P8 php -l
	vendor/squizlabs/php_codesniffer/bin/phpcs

.PHONY: lint-fix
lint-fix:  ## PHP Lint Fix
	composer -q install
	vendor/squizlabs/php_codesniffer/bin/phpcbf
