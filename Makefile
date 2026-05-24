.PHONY: install test stan cs cs-fix rector ff

install:
	composer install

test:
	vendor/bin/phpunit

stan:
	vendor/bin/phpstan analyse --no-progress --memory-limit=512M

cs:
	vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	vendor/bin/php-cs-fixer fix

rector:
	vendor/bin/rector process --dry-run

ff: install test stan cs rector
