SW_API_HOSTNAME ?= api.synergywholesale.com
MODULE_NAME ?= synergywholesale_microsoft365
RELEASE_DATE := $(shell date '+%A, %B %d %Y')

# Make sure sed replace works on Mac OSX
SED_PARAM := 
ifeq ($(shell uname -s),Darwin)
	SED_PARAM += ''
endif

# In case the version tag isn't annoated, let's have a fallback
VERSION := $(shell git describe --abbrev=0)
ifneq ($(.SHELLSTATUS), 0)
	VERSION := $(shell git describe --tags)
endif

VERSION := $(firstword $(subst -, ,${VERSION}))

replace:
	sed -i${SED_PARAM} "s/{{VERSION}}/${VERSION}/g" "README.txt"
	sed -i${SED_PARAM} "s/{{VERSION}}/${VERSION}/g" "modules/servers/synergywholesale_microsoft365/whmcs.json"
	sed -i${SED_PARAM} "s/{{RELEASE_DATE}}/${RELEASE_DATE}/g" "README.txt"
	sed -i${SED_PARAM} "s/{{API}}/${SW_API_HOSTNAME}/g" "modules/servers/synergywholesale_microsoft365/models/SynergyAPI.php"
	sed -i${SED_PARAM} "s/{{MODULE_NAME}}/${MODULE_NAME}/g" "modules/servers/synergywholesale_microsoft365/synergywholesale_microsoft365.php"
	sed -i${SED_PARAM} "s/{{MODULE_NAME}}/${MODULE_NAME}/g" "modules/servers/synergywholesale_microsoft365/models/SynergyAPI.php"

revert:
	sed -i${SED_PARAM} "s/{{VERSION}}/${VERSION}/g" "README.txt"
	sed -i${SED_PARAM} "s/{{VERSION}}/${VERSION}/g" "modules/servers/synergywholesale_microsoft365/whmcs.json"
	sed -i${SED_PARAM} "s/{{RELEASE_DATE}}/${RELEASE_DATE}/g" "README.txt"
	sed -i${SED_PARAM} "s/{{API}}/${SW_API_HOSTNAME}/g" "modules/servers/synergywholesale_microsoft365/models/SynergyAPI.php"
	sed -i${SED_PARAM} "s/{{MODULE_NAME}}/${MODULE_NAME}/g" "modules/servers/synergywholesale_microsoft365/synergywholesale_microsoft365.php"
	sed -i${SED_PARAM} "s/{{MODULE_NAME}}/${MODULE_NAME}/g" "modules/servers/synergywholesale_microsoft365/models/SynergyAPI.php"

package:
	make replace
	zip -r "synergy-wholesale-microsoft365-$(VERSION).zip" . -x  \
	'.DS_Store' '**/.DS_Store' '*.cache' '.git*' '*.md' 'Makefile' 'package.json' 'package-lock.json' \
	'composer.json' 'composer.lock' '*.xml' \
	'vendor/*' 'node_modules/*' '.git/*' 'tests/*'
	make revert

build:
	test -s node_modules/.bin/minify || npm install
	make replace
	make package
	make revert

test:
	test -s vendor/bin/phpcs || composer install
	./vendor/bin/phpcs
	./vendor/bin/phpunit
	test -s node_modules/.bin/minify || npm install

tools:
	npm install
	composer install
