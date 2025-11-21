# Cashu For WooCommerce

Thank you for your interest in contributing to Cashu For WooCommerce. This project extends WooCommerce with Cashu payments, so changes need to be stable, secure and easy for other developers to follow.

This guide explains how to set up the development environment, run the tooling, and prepare changes before opening a pull request.

## Getting started

You will need

* Node and npm installed
* A local clone of this repository
* A WordPress and WooCommerce development environment if you want to test the plugin in a real site

From your terminal

```bash
cd /path/to/cashu-for-woocommerce
npm install
````

This installs all the development dependencies, including Grunt and Prettier.

## Grunt tasks

We use Grunt to automate some housekeeping tasks in the plugin, such as creating the README, updating translation files and so on.

You will need the Grunt CLI installed globally

```bash
npm install -g grunt-cli
```

That gives you the grunt command globally so you can type `grunt` in any project. On some systems you might need sudo, but only if your environment normally needs that for global npm installs

```bash
sudo npm install -g grunt-cli
```

Now from the plugin root, you can run

```bash
grunt
# or via npm script
npm run start
```

That will

* Scan PHP files and ensure all translation functions use the cashu-for-woocommerce text domain
* Regenerate the languages cashu-for-woocommerce pot file
* Convert readme txt into README md

### Useful separate commands

Only translation work

```bash
grunt i18n
# or
npm run i18n
```

Only readme conversion

```bash
grunt readme
# or
npm run readme
```

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

Before opening a pull request, please run format so your changes match the existing style.

## Internationalisation

Cashu For WooCommerce is prepared for translation using the `cashu-for-woocommerce` text domain.

When adding or updating strings in PHP

* Use standard WordPress translation functions, for example:\
`__()`, `_e()`, `_x()`, `esc_html__`, `esc_attr__` and so on
* Always pass cashu-for-woocommerce as the text domain

Example

```php
__( 'Pay with Cashu', 'cashu-for-woocommerce' );
```

If you are unsure whether your strings are correctly set up, run

```bash
grunt i18n
```

which will update text domains where needed and regenerate the pot file.

## Development workflow

A typical workflow for a small change might look like this

1. Create a new branch from main

2. Make your code changes

3. Run the tools

   ```bash
   npm run format
   npm run i18n
   npm run readme
   ```

4. Test the plugin in your WordPress WooCommerce environment

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
