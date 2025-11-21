# Cashu For WooCommerce

Thank you for your interest in contributing to Cashu For WooCommerce. This project extends WooCommerce with Cashu payments, so changes need to be stable, secure and easy for other developers to follow.

This guide explains how to set up the development environment, run the tooling, use the wp-env WordPress environment, and prepare changes before opening a pull request.

## Getting started

You will need

* Node and npm for build, i18n and readme
* PHP and Composer for running unit tests
* Docker for the wp-env local WordPress environment
* A local clone of this repository

Optionally, you can also install wp-env either globally or as a dev dependency, so you can run a disposable WordPress and WooCommerce site for testing the gateway.

From your terminal

```bash
cd /path/to/cashu-for-woocommerce
npm install
composer install
```

This installs all the development dependencies, including Grunt, Prettier, Vite and PHPUnit.

The rest of this file gives details on all the developer tools, but here's a TL;DR:

**TL;DR:**
```bash
# Spin up a WordPress server and WooCommerce store with plugin installed
npm run wp-env:start
npm run wp-env:seed-store

# Reset or tear it down
npm run wp-env:reset
npm run wp-env:destroy

# Prep for release
npm run start
npm run format
npm run build
```

## Local WordPress and WooCommerce environment with wp-env

This repository includes a `.wp-env.json` and npm scripts to spin up a complete WordPress site with WooCommerce and the plugin already active.

The environment runs two sites

* Development site at `http://localhost:8888`
* Test site at `http://localhost:3000`

Both are separate WordPress installs backed by separate databases, but they share the same code from this repository.

### Installing wp-env

You can use a global install, a local dev dependency, or both.

Global install

```bash
npm -g install @wordpress/env
```

The local dev dependency is installed automatically when you run

```bash
npm install
```

If you have it as a dev dependency, the existing npm scripts will use the local `node_modules/.bin/wp-env` binary, which keeps everyone on the same version.

### Starting the environment

From the plugin root

```bash
npm run wp-env:start
```

This uses your `.wp-env.json` to

* Download and configure WordPress
* Install WooCommerce and Email Log from the official plugin zips
* Mount this repository as a plugin and activate it
* Load the `one-theme` theme in the development environment

When it finishes you should see something similar to

```text
WordPress development site started at http://localhost:8888
WordPress test site started at http://localhost:3000
```

Log in to the development site at

* URL, [http://localhost:8888/wp-admin](http://localhost:8888/wp-admin)
* User, `admin`
* Password, `password`

The Cashu For WooCommerce plugin and WooCommerce should already be active.

### Seeding a dummy WooCommerce store

To quickly get products into your development store, the `package.json` includes a helper script which uses WooCommerce’s own sample data.

From the plugin root

```bash
npm run wp-env:seed-store
```

This will

* Install and activate the WordPress importer inside the wp-env container
* Import the `sample_products.xml` file that ships with WooCommerce into the development site

After that, you will usually need to activate the Cashu for WooCommerce plugin and set it up:

* Go to WooCommerce, Settings, Payments
* Enable the Cashu ecash plugin

You should now have a working dummy store where you can place test orders through the Cashu gateway straight away.

### Development site vs test site

The development site at `http://localhost:8888` is your playground. This is where you

* Configure WooCommerce and the Cashu gateway
* Change settings, run through the setup wizard, install extra plugins
* Place manual test orders and watch how the gateway behaves

The test site at `http://localhost:8889` uses a separate database and is intended for automated tests or destructive experiments. You can target it from wp-env using the `tests-cli` container if you decide to run tests inside Docker later on.

At the moment, the npm scripts run wp-env commands against the development site via

```bash
wp-env run cli ...
```

so your dummy store data and XML imports all go to `http://localhost:8888`.

### Reading logs

As part of development, you will likely want to view / tail the various logs.

**Apache log:**

The following command will tail the Apache webserver log:

```bash
wp-env logs
```

**PHP logs:**

The PHP log is stored in the `debug.log` file in the plugin root folder. You can tail it with:

```bash
tail -f debug.log
```

**WooCommerce logs:**

The plugin logs to the WooCommerce internal log as well. View that at inside WordPress:

```
WordPress Admin > WooCommerce > Status > Logs
```

### Stopping and cleaning the environment

To stop the containers without losing data

```bash
npm run wp-env:stop
```

To reset the WordPress database for the current project you can use

```bash
npm run wp-env:clean
```

which runs `wp-env clean` under the hood. If you ever need a full reset for both development and test databases you can run, from the project root

```bash
wp-env clean all
wp-env start
```

or, as a last resort

```bash
wp-env destroy
wp-env start
```

Your plugin code lives in your git checkout, so cleaning or destroying the environment only affects the WordPress databases and containers, not your source files.

### Editing the plugin

Because the plugin is mounted into the container from the current directory, changes you make to PHP files are reflected immediately in the development site.

Typical loop

```bash
# In one terminal
npm run wp-env:start

# In another terminal
edit src/Gateway/CashuGateway.php
npm run build     # if you changed TS or JS assets
```

Then refresh the page in the browser. There is no need to restart the wp-env containers for normal plugin changes.

## Useful commands

We use a Gruntfile to perform various tasks, which you can run via npm scripts

```bash
npm run start
```

That will

* Scan PHP files and ensure all translation functions use the cashu-for-woocommerce text domain
* Regenerate the languages/cashu-for-woocommerce.pot file
* Convert readme.txt into README.md

You can run these individually if you prefer.

Only translation work

```bash
npm run i18n
```

Only readme conversion

```bash
npm run readme
```

If you have `grunt-cli` installed globally you can also run `grunt`, `grunt i18n` or `grunt readme` directly, but this is optional because the npm scripts always use the local Grunt binary in node_modules.

## Code style and formatting

We use Prettier to keep the code style consistent, especially for PHP in the main plugin file and the src folder.

To check formatting

```bash
npm run format:check
```

To automatically fix formatting issues

```bash
npm run format
```

We also have PHP linting using phpstan. Run it with

```bash
composer stan
# or via npm
npm run lint:php
```

Before opening a pull request, please run `npm run format` so your changes match the existing style.

## Internationalisation

Cashu For WooCommerce is prepared for translation using the `cashu-for-woocommerce` text domain.

When adding or updating strings in PHP

* Use standard WordPress translation functions, for example `__()`, `_e()`, `_x()`, `esc_html__()`, `esc_attr__()` and so on
* Always pass `cashu-for-woocommerce` as the text domain

Example

```php
__( 'Pay with Cashu', 'cashu-for-woocommerce' );
```

If you are unsure whether your strings are correctly set up, run

```bash
npm run i18n
```

which will update text domains where needed and regenerate the pot file.

## Running tests

PHP unit tests are managed with PHPUnit via Composer.

To run the test suite

```bash
composer test
# or via npm
npm run test
```

This will execute the tests defined in the test suite using the PHPUnit binary in `vendor/bin/phpunit`.

Please run the tests before opening a pull request, especially if you have changed any PHP code.

If you prefer to run tests inside the wp-env containers, you can also use `wp-env run`, for example

```bash
wp-env run tests-cli phpunit
```

but this is optional and not required for basic contributions.

## Development workflow

A typical workflow for a small change might look like this

1. Create a new branch from main

2. Make your code changes

3. Run the tools

	```bash
	npm run format
	npm run i18n
	npm run readme
	npm run test
	```

4. Start or update the wp-env WordPress site and test the plugin in a WooCommerce store

	```bash
	npm run wp-env:start
	npm run wp-env:seed-store   # optional, to get demo products
	```

5. Commit your changes with a clear message

6. Push your branch and open a pull request

Please keep pull requests focused on a single change where possible, for example a bug fix, a new feature, or a documentation update. This makes review much easier.

## Reporting issues

If you find a bug or have a feature request, please include

* A clear description of the problem or idea
* Steps to reproduce the issue if it is a bug
* Your WordPress version, WooCommerce version and PHP version
* Any relevant error messages or logs

This information helps us understand and address the issue more quickly.

## Thank you

Every contribution, whether it is code, documentation, testing or feedback, helps improve Cashu For WooCommerce. Thank you for taking the time to help.
