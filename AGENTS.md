<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

## Git identity required by repository owner

- Every Git commit and push must use author and committer `Deni Afrizal <deniafrizal2904@gmail.com>`.
- Never add `Co-authored-by` trailers or attribute commits to another contributor.
- The primary branch for this repository is `Main` (case-sensitive).
- Keep `.env`, `.env.testing`, credentials, runtime data, vendor and private uploads out of Git.
- This checkout enables `.githooks` through local `core.hooksPath`. After cloning, set `git config --local user.name "Deni Afrizal"`, `git config --local user.email "deniafrizal2904@gmail.com"`, `git config --local user.useConfigOnly true`, and `git config --local core.hooksPath .githooks`.
