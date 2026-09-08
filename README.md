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
`Amp\File`-backed rather than Flysystem's own local adapter, so a driver
call suspends the calling Fiber instead of blocking the whole worker
process. `readStream()` buffers the whole object into a `php://temp`
resource; `writeStream()` transfers the caller's own resource in
bounded chunks, reading it with PHP's native stream functions, whose
reads block the thread whenever they reach a disk. Remote backends
(S3, etc.) live in the separate
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
`write()`, `writeStream()` and `copy()` build the new file in a private
directory beside the destination and rename it into place once its
stored length matches, so a reader sees the whole old file or the whole
new one and a call that fails before that rename leaves the destination
as it was. A failed publication reports the operation's declared
`League\Flysystem\UnableTo*` failure, and a failure reported by the
rename itself leaves the outcome unknown — the adapter reports what the
call answered, and a lost answer is not a rename that did not happen.
Retrying is safe where this caller is the only writer to that path;
where writers compete for one, serializing ownership of it is the
caller's to arrange, since every operation here replaces
unconditionally. The staging directory's name is reserved: no listing
reports one at any depth, a recursive deletion still removes it, and a
path naming one is refused. Each operation reports a driver failure as
the `League\Flysystem\UnableTo*` type its own interface declares, while a
policy outcome (`PathTraversalDetected`, `CorruptedPathDetected`,
`SymbolicLinkEncountered`, `ReservedPathDetected`,
`InvalidVisibilityProvided`) and a programmer error both keep their own
type. Symlinks are checked, with a disclosed limit that is not a
security boundary against a concurrent writer:
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
