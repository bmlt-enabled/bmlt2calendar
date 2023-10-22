COMMIT := $(shell git rev-parse --short=8 HEAD)
ZIP_FILENAME := $(or $(ZIP_FILENAME), $(shell echo "$${PWD\#\#*/}.zip"))
BUILD_DIR := $(or $(BUILD_DIR),"build")
VENDOR_AUTOLOAD := vendor/autoload.php

help:  ## Print the help documentation
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

.PHONY: build
build:  composer ## Build
	git archive --format=zip --output=${ZIP_FILENAME} $(COMMIT)
	zip -r ${ZIP_FILENAME} vendor/
	mkdir ${BUILD_DIR} && mv ${ZIP_FILENAME} ${BUILD_DIR}/

clean:  ## clean
	rm -rf build dist

$(VENDOR_AUTOLOAD):
	composer install --prefer-dist --no-progress

.PHONY: composer
composer: $(VENDOR_AUTOLOAD) ## Runs composer install

.PHONY: lint
lint:  composer ## PHP Lint
	find . -name "*.php" ! -path '*/vendor/*' -print0 | xargs -0 -n1 -P8 php -l
	vendor/squizlabs/php_codesniffer/bin/phpcs

.PHONY: lint-fix
lint-fix:  composer ## PHP Lint Fix
	vendor/squizlabs/php_codesniffer/bin/phpcbf
