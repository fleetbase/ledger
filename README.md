<p align="center">
    <p align="center">
        <img src="https://github.com/user-attachments/assets/197c404c-06b1-4fd4-8a26-624f1d481966" width="140" height="140" />
    </p>
    <p align="center">
        Accounting & Invoicing Extension for Fleetbase
    </p>
</p>

---

## Overview

This monorepo contains both the frontend and backend components of the Ledger extension for Fleetbase. The frontend is built using Ember.js and the backend is implemented in PHP.

* PHP 7.3.0 or above
* Ember.js v4.8 or above
* Ember CLI v4.8 or above
* Node.js v18 or above

## Structure

```
├── addon
├── app
├── assets
├── translations
├── config
├── node_modules
├── server
│ ├── config
│ ├── data
│ ├── migrations
│ ├── resources
│ ├── src
│ ├── tests
│ └── vendor
├── tests
├── testem.js
├── index.js
├── package.json
├── phpstan.neon.dist
├── phpunit.xml.dist
├── pnpm-lock.yaml
├── ember-cli-build.js
├── composer.json
├── CONTRIBUTING.md
├── LICENSE.md
├── README.md
```

## Installation

### Backend

Install the PHP packages using Composer:

```bash
composer require fleetbase/core-api
composer require fleetbase/fleetops-api
composer require fleetbase/ledger-api
```
### Frontend

Install the Ember.js Engine/Addon:

```bash
pnpm install @fleetbase/ledger-engine
```

## Usage

### Backend

🧹 Keep a modern codebase with **PHP CS Fixer**:
```bash
composer lint
```

⚗️ Run static analysis using **PHPStan**:
```bash
composer test:types
```

✅ Run unit tests using **PEST**
```bash
composer test:unit
```

🚀 Run the entire test suite:
```bash
composer test
```

### Frontend

🧹 Keep a modern codebase with **ESLint**:
```bash
pnpm lint
```

✅ Run unit tests using **Ember/QUnit**
```bash
pnpm test
pnpm test:ember
pnpm test:ember-compatibility
```

🚀 Start the Ember Addon/Engine
```bash
pnpm start
```

🔨 Build the Ember Addon/Engine
```bash
pnpm build
```

## Contributing
See the Contributing Guide for details on how to contribute to this project.

## License
This project is licensed under the MIT License.
