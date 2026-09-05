<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/storage</strong>
  <br>
  <strong>File storage for Kinetis, built on <code>League\Flysystem</code></strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/storage"><img src="https://img.shields.io/packagist/v/kinetis/storage?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/storage"><img src="https://img.shields.io/packagist/dt/kinetis/storage" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/storage"><img src="https://img.shields.io/packagist/php-v/kinetis/storage" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/storage"><img src="https://img.shields.io/packagist/l/kinetis/storage" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

Read, write, delete, and list files against `League\Flysystem`'s
`FilesystemOperator` interface — swappable to a different backend with no
application-code changes. Local storage is Kinetis's own backend,
`Amp\File`-backed rather than Flysystem's own local adapter, so every
operation genuinely suspends the calling Fiber instead of blocking the
whole worker process. Remote backends (S3, etc.) live in the separate
[`kinetis/storage-s3`](https://github.com/kinetis-dev/storage-s3).

```php
use Kinetis\Storage\FilesystemFactory;

$storage = FilesystemFactory::fromConfig($config);

$storage->write('avatars/user-42.png', $imageContents);
$contents = $storage->read('avatars/user-42.png');
$storage->delete('avatars/user-42.png');
```

Every path is confined to `FILESYSTEM_ROOT` before it becomes a
filesystem location — a `..` segment, a control byte or a backslash is
refused with no filesystem call made, on both operands of a `move()` or
a `copy()`, and so is a write whose destination names the root itself.
Each operation reports a driver failure as the
`League\Flysystem\UnableTo*` type its own interface declares — including
a failure from the worker pool `amphp/file` runs its calls in — while a
policy outcome (`PathTraversalDetected`, `CorruptedPathDetected`,
`SymbolicLinkEncountered`, `InvalidVisibilityProvided`) and a programmer
error both keep their own type. Symlinks are checked, with a disclosed
limit that is not a security boundary against a concurrent writer:
[kinetis.dev/docs/storage.html](https://kinetis.dev/docs/storage.html).

## Provides

Installing this package auto-registers, via `extra.kinetis`:

- **A container binding** for `League\Flysystem\FilesystemOperator`,
  built by `FilesystemFactory::fromConfig()` when `FILESYSTEM_DRIVER` is
  set. Unset means the package binds nothing. The binding is lazy, so an
  application that never injects a filesystem never builds one.

Nothing else. Named connections stay explicit application wiring.

## Configuration

Read from the environment (or `.env`) via `Kinetis\Config`. Every key
is scoped.

| Key | Default | Purpose |
|---|---|---|
| `FILESYSTEM_DRIVER` | *(unset)* | `local`, or `s3` (needs [`kinetis/storage-s3`](https://github.com/kinetis-dev/storage-s3)). Unset binds nothing; `FilesystemFactory::fromConfig()`, called directly, falls back to `local`. |
| `FILESYSTEM_ROOT` | *(required for local)* | Local disk root path. Must be non-empty; `/` is valid. |

Scoped keys follow the named-connection convention — the connection
name inserts after the first segment: `FILESYSTEM_ROOT` + `uploads` → `FILESYSTEM_UPLOADS_ROOT`.
Full reference across every package:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

## Installation

```sh
composer require kinetis/storage
```

Requires PHP 8.4+ and [`kinetis/framework`](https://github.com/kinetis-dev/framework). Full documentation:
[kinetis.dev/docs/storage.html](https://kinetis.dev/docs/storage.html).

## License

MIT — see [LICENSE](LICENSE).
