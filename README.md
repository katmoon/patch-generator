# Patch Generator

A tool for automatically generating patches from Jira tickets and GitHub pull requests.

### Requirements
- PHP 8.3 or higher

## Features

- Automatically fetches pull request information from Jira tickets or provide PR URLs as an option
- Supports multiple GitHub repositories in a single ticket
- Converts GitHub pull requests into Composer-compatible patches
- Supports multiple patch versions

## Installation

1. Clone the repository:
```sh
git clone <repository-url> patch-generator
cd patch-generator
```

2. Install dependencies:
```sh
composer install
```

3. Create a `.env` file in your project root or home directory with the required variables
```sh
cp .env.example .env
```
Configure your `.env` file with the required variables.
The tool will look for the `.env` file in the following locations:
1. Current working directory
2. User's home directory
Environment variables can also be set directly in the shell or through your system's environment configuration.

## Usage

```sh
php patch-generator.php JIRA-123 [-v|--patch-version VERSION] [-g|--git-pr PR_URL]
```

### Arguments

- `JIRA-123`: The Jira ticket ID (required)
- `-v|--patch-version`: The patch version, e.g. 2 (optional)
  - Will be formatted as _v2 in the patch name
  - Accepts: 2, v2, _v2 (all produce the same result)
  - Version 1 is ignored (no suffix added)
- `-g, --git-pr=URLS`: Comma-separated list of GitHub PR URLs (optional)
  - If provided, these PRs will be used instead of those from the Jira ticket

### Examples

```sh
# Generate patch from Jira ticket
php patch-generator.php JIRA-123

# Generate patch with version
php patch-generator.php JIRA-123 -v 2

# Generate patch from specific PR
php patch-generator.php JIRA-123 -g https://github.com/owner/repo/pull/123
```

# Combine version and specific PRs
php patch-generator.php JIRA-1234 -p 2 -g "https://github.com/owner/repo/pull/123 https://github.com/owner/repo/pull/456"

# Show help
php patch-generator.php --help

# Show application version
php patch-generator.php --version
```

### Output Files

The tool generates two types of patch files:
- `<TICKET-ID>_<VERSION>[_v<N>].git.patch`: Git patch
- `<TICKET-ID>_<VERSION>[_v<N>].patch`: Composer-compatible patch

### Patch Naming

The patch filename is constructed using:
- Ticket ID (e.g., JIRA-1234)
- Release version (automatically extracted from the Jira ticket's "Fix Version" field)
- Optional DEBUG suffix (if any source branch contains "_DEBUG")
- Optional CUSTOM suffix (if any source branch contains "_CUSTOM")
- Optional version suffix (if specified with -p option)

Example filenames:
- `JIRA-1234_2.4.7.patch` (basic patch for 2.4.7 release)
- `JIRA-1234_2.4.7_v2.patch` (version 2 patch for 2.4.7 release)
- `JIRA-1234_2.4.7_DEBUG_v2.patch` (patch from a DEBUG branch)
- `JIRA-1234_2.4.7_CUSTOM.patch` (patch from a CUSTOM branch)

## Testing
```sh
composer test
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

The MIT License is a permissive free software license that places only very limited restriction on reuse and has, therefore, an excellent license compatibility. It is compatible because it is one of the most permissive licenses, and is compatible with all other licenses.

### MIT License Summary

- **Commercial use**: The licensed material and derivatives may be used for commercial purposes.
- **Modification**: The licensed material may be modified.
- **Distribution**: The licensed material may be distributed.
- **Private use**: The licensed material may be used and modified in private.
- **Liability**: This license includes a limitation of liability.
- **Warranty**: This license explicitly states that it does NOT provide any warranty.

For the full license text, please see the [LICENSE](LICENSE) file.