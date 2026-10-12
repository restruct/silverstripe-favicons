<?php

declare(strict_types=1);

namespace Restruct\Favicons;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Throwable;

/**
 * Copies the files FaviconService answers at root paths (favicon.ico, apple-touch-icon*.png,
 * site.webmanifest ...) into the public webroot, so the web server serves them as static files
 * and the PHP route (FaviconMiddleware) is only the fallback.
 *
 * Runs on dev/build and when the icon changes in the CMS, never on a normal request. Writes only
 * when `write_public_files` is on, the site is not in dev mode (unless `write_public_files_in_dev`),
 * and the webroot is writable; otherwise nothing is written and the route keeps answering.
 *
 * Which files this module wrote is kept in a ledger in the module cache, per webroot: for each path
 * the token of what was written (the set id for an image, the served version for the manifest)
 * plus the file's size, mtime and inode. A path in the ledger is the module's own. On each sync a
 * file is only (re)written when it is missing, when its token is not the current one (a new icon
 * or config, so a new set), or when its stat no longer matches (replaced since). An unchanged
 * dev/build therefore costs a stat per file: no reads from the AssetStore, no hashing, no writes,
 * the same "only when the target is missing or out of date" rule the framework follows for image
 * variants.
 *
 * A file at one of these paths that is NOT in the ledger was put there by someone else (a project
 * skeleton's favicon.ico): it is renamed in place to a backup name such as
 * `favicon.bu-20261012.ico`, unless it is byte-identical to the new file, in which case it is
 * simply adopted. Either way the path is the module's from then on, so a later replacement is
 * overwritten without another backup and backups cannot accumulate.
 *
 * A ledger rather than comparing against generated content, because an OLD icon the module wrote
 * cannot be regenerated once its set is gone: without the ledger every icon change would back up
 * the module's own previous file. It lives in the same cache as the registry of generated sets, so
 * it is shared by dev/build and the web process exactly as that registry is. When it is lost the
 * worst case is one unnecessary backup, never a lost file.
 */
class PublicFileWriter
{
    use Injectable;

    /**
     * Result statuses of sync().
     */
    public const STATUS_SYNCED = 'synced';
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_UNWRITABLE = 'unwritable';
    public const STATUS_OFF = 'off';
    public const STATUS_DEV = 'dev';

    /**
     * Override of the webroot (tests use a temp folder); null means Director::publicFolder().
     */
    protected ?string $publicFolder = null;

    /**
     * Re-entrance guard for sync().
     */
    protected bool $syncing = false;

    public function setPublicFolder(?string $folder): static
    {
        $this->publicFolder = $folder;
        return $this;
    }

    public function getPublicFolder(): string
    {
        return rtrim($this->publicFolder ?? Director::publicFolder(), '/');
    }

    /**
     * Why nothing is written: STATUS_OFF (write_public_files: false), STATUS_DEV (dev mode without
     * write_public_files_in_dev), or null when writing is switched on.
     */
    public function getDisabledReason(): ?string
    {
        $cfg = FaviconService::config();
        if (!$cfg->get('write_public_files')) {
            return self::STATUS_OFF;
        }
        # In dev the webroot is usually a git working tree: written and renamed files would show up
        # in git status, and a renamed favicon.ico as a deletion.
        if ($this->isDevEnvironment() && !$cfg->get('write_public_files_in_dev')) {
            return self::STATUS_DEV;
        }
        return null;
    }

    protected function isDevEnvironment(): bool
    {
        return Director::isDev();
    }

    /**
     * Every root path the module answers that can be a plain file in the webroot, path => variant
     * name, minus `disabled_paths`. "What gets served" in the README, except the open-ended
     * apple-touch-icon-NxN probes (any N) that are not a generated size, which stay with the route.
     * An `extra_paths` alias is included only when it is a plain file name at the root: a nested
     * alias would need a directory in the webroot, which can shadow a page URL of the same name.
     *
     * @return array<string, string>
     */
    public function getPublicPaths(): array
    {
        $service = FaviconService::singleton();
        $paths = ['favicon.ico', 'apple-touch-icon.png', 'apple-touch-icon-precomposed.png'];
        foreach ($service->getTouchSizes() as $n) {
            $paths[] = "apple-touch-icon-{$n}x{$n}.png";
        }
        array_push($paths, 'icon-192.png', 'icon-512.png', 'icon-maskable-512.png', 'site.webmanifest', 'manifest.json');
        foreach (array_keys((array) FaviconService::config()->get('extra_paths')) as $alias) {
            $alias = strtolower(ltrim((string) $alias, '/'));
            if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $alias)) {
                $paths[] = $alias;
            }
        }
        $result = [];
        foreach (array_unique($paths) as $path) {
            # resolveRequestPath() applies disabled_paths and the alias mapping, as for a request.
            $variant = $service->resolveRequestPath($path);
            if ($variant !== null) {
                $result[$path] = $variant;
            }
        }
        return $result;
    }

    /**
     * Bring the webroot in line with what the module serves now: write missing or outdated files,
     * back up a foreign file in the way, remove files the module wrote earlier that it no longer
     * answers (a disabled path, a removed source, the feature switched off). Never throws.
     *
     * @return array{status:string, folder:string, written:string[], renamed:array<string, string>, removed:string[], current:string[], failed:array<string, string>}
     */
    public function sync(): array
    {
        $folder = $this->getPublicFolder();
        $result = ['status' => self::STATUS_UNCHANGED, 'folder' => $folder, 'written' => [], 'renamed' => [], 'removed' => [], 'current' => [], 'failed' => []];
        # A sync can write SiteConfig indirectly (generation reads it), whose hooks sync again.
        if ($this->syncing) {
            return $result;
        }
        $this->syncing = true;
        try {
            return $this->doSync($folder, $result);
        } catch (Throwable $e) {
            $result['failed']['*'] = $e->getMessage();
            $this->log('warning', 'Favicon files in the webroot could not be updated: ' . $e->getMessage());
            return $result;
        } finally {
            $this->syncing = false;
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    protected function doSync(string $folder, array $result): array
    {
        $cache = FaviconService::singleton()->getCache();
        $key = $this->ledgerKey($folder);
        $ledger = $cache->get($key);
        $ledger = is_array($ledger) && is_array($ledger['files'] ?? null) ? $ledger : ['files' => []];

        $disabled = $this->getDisabledReason();
        if ($disabled !== null && !$ledger['files']) {
            # Switched off and nothing of ours to clean up: not even a stat of the webroot.
            $result['status'] = $disabled;
            return $result;
        }
        if (!is_dir($folder) || !is_writable($folder)) {
            # Creating, renaming and deleting all need a writable directory. The route still answers.
            $result['status'] = self::STATUS_UNWRITABLE;
            return $result;
        }

        # path => [variant, token] of every file that should be in the webroot now; no bytes yet
        $wanted = $disabled === null ? $this->wantedFiles() : [];
        # variant => bytes, read from the store only for a file that is actually (re)written
        $bodies = [];
        $changed = false;

        foreach (array_unique(array_merge(array_keys($wanted), array_keys($ledger['files']))) as $path) {
            $path = (string) $path;
            $target = $folder . '/' . $path;
            $recorded = $ledger['files'][$path] ?? null;
            $recorded = is_array($recorded) ? $recorded : null;

            if (!isset($wanted[$path])) {
                # Ours earlier, not answered any more. Removed only while it is still what we wrote:
                # a file replaced since (by a deploy or by hand) is somebody else's now.
                unset($ledger['files'][$path]);
                $changed = true;
                if ($recorded && $this->statMatches($target, $recorded)) {
                    if (@unlink($target)) {
                        $result['removed'][] = $path;
                    } else {
                        $result['failed'][$path] = 'could not be removed';
                        $ledger['files'][$path] = $recorded;
                    }
                }
                continue;
            }

            [$variant, $token] = $wanted[$path];
            if ($recorded && ($recorded['token'] ?? null) === $token && $this->statMatches($target, $recorded)) {
                # Written from the current set and untouched since: nothing to read or write.
                $result['current'][] = $path;
                continue;
            }

            if (!array_key_exists($variant, $bodies)) {
                $bodies[$variant] = $this->readVariant($variant);
            }
            $bytes = $bodies[$variant];
            if ($bytes === null) {
                $result['failed'][$path] = 'not available from the store';
                continue;
            }

            $exists = is_link($target) || file_exists($target);
            if ($exists && is_dir($target) && !is_link($target)) {
                $result['failed'][$path] = 'a directory is in the way';
                continue;
            }
            if ($exists && !$recorded) {
                # Not ours. Identical bytes are adopted as they are; anything else is kept, renamed
                # in place. Only this once: from now on the path is in the ledger.
                if (!is_link($target) && sha1_file($target) === sha1($bytes)) {
                    $ledger['files'][$path] = $this->record($target, $token);
                    $changed = true;
                    $result['current'][] = $path;
                    continue;
                }
                $backup = $this->backupName($folder, $path);
                if (!@rename($target, $folder . '/' . $backup)) {
                    $result['failed'][$path] = 'the existing file could not be renamed';
                    continue;
                }
                $result['renamed'][$path] = $backup;
            }
            if ($this->writeFile($folder, $path, $bytes)) {
                $ledger['files'][$path] = $this->record($target, $token);
                $result['written'][] = $path;
            } else {
                $result['failed'][$path] = 'could not be written';
                unset($ledger['files'][$path]);
            }
            $changed = true;
        }

        if ($changed) {
            $cache->set($key, $ledger);
        }

        $result['status'] = $disabled ?? ($changed ? self::STATUS_SYNCED : self::STATUS_UNCHANGED);
        foreach ($result['renamed'] as $path => $backup) {
            # Also logged, because a sync from the CMS has no dev/build output to show it in.
            $this->log('warning', "Favicons: an existing {$path} in the webroot was renamed to {$backup}; remove {$path} from the project, this module writes it now.");
        }
        return $result;
    }

    /**
     * Every public path that should be a file now, with its variant and the token of its content:
     * the set id for an image (the set is content-addressed: source hash plus config), the served
     * version for the manifest (it also carries the site title and theme colour). An image path is
     * only wanted while the current source has a complete set; without one (no source, an
     * undecodable one, a set being built) only the manifest remains, and image files the module
     * wrote earlier are removed so the route answers instead of a stale file.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    protected function wantedFiles(): array
    {
        $service = FaviconService::singleton();
        $source = $service->getSource();
        if (!$source) {
            return [];
        }
        $set = $service->getCompletedSet($source);
        $variants = $service->getVariants($source);
        $files = [];
        foreach ($this->getPublicPaths() as $path => $variant) {
            if (!isset($variants[$variant])) {
                continue;
            }
            if ($variants[$variant]['format'] === 'manifest') {
                $token = 'manifest:' . $service->getVersion($source);
            } elseif ($set !== null) {
                # The variant name too: an alias may be pointed at another variant of the same set.
                $token = $set['id'] . ':' . $variant;
            } else {
                continue;
            }
            $files[$path] = [$variant, $token];
        }
        return $files;
    }

    protected function readVariant(string $variant): ?string
    {
        $data = FaviconService::singleton()->openVariant($variant);
        if ($data === null) {
            return null;
        }
        if (isset($data['stream'])) {
            $bytes = stream_get_contents($data['stream']);
            fclose($data['stream']);
            return $bytes === false || $bytes === '' ? null : $bytes;
        }
        return (string) $data['body'];
    }

    /**
     * Ledger entry for a file just written or adopted.
     *
     * @return array{token:string, size:int, mtime:int, ino:int}
     */
    protected function record(string $target, string $token): array
    {
        clearstatcache(true, $target);
        $stat = @stat($target) ?: ['size' => -1, 'mtime' => -1, 'ino' => -1];
        return ['token' => $token, 'size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime'], 'ino' => (int) $stat['ino']];
    }

    /**
     * Whether the file is still the one recorded: same size, mtime and inode (a replacement written
     * through a rename, as deploys and editors do, gets a new inode even at the same size and time).
     *
     * @param array<string, mixed> $recorded
     */
    protected function statMatches(string $target, array $recorded): bool
    {
        clearstatcache(true, $target);
        if (is_link($target) || !is_file($target)) {
            return false;
        }
        $stat = @stat($target);
        return is_array($stat)
            && (int) $stat['size'] === (int) ($recorded['size'] ?? -2)
            && (int) $stat['mtime'] === (int) ($recorded['mtime'] ?? -2)
            && (int) $stat['ino'] === (int) ($recorded['ino'] ?? -2);
    }

    /**
     * Per webroot, by its real path: a zero-downtime deploy reaches the same release through a
     * `current` symlink on the web and through the release path on the command line.
     */
    protected function ledgerKey(string $folder): string
    {
        return 'public_' . md5(realpath($folder) ?: $folder);
    }

    /**
     * favicon.ico -> favicon.bu-20261012.ico (then favicon.bu-20261012-2.ico ...): next to the
     * original, with the real extension last so the web server still serves it with its type.
     */
    public function backupName(string $folder, string $path): string
    {
        $dot = strrpos($path, '.');
        $stem = $dot === false ? $path : substr($path, 0, $dot);
        $ext = $dot === false ? '' : substr($path, $dot);
        $base = $stem . '.bu-' . date('Ymd');
        $name = $base . $ext;
        for ($i = 2; file_exists($folder . '/' . $name) || is_link($folder . '/' . $name); $i++) {
            $name = $base . '-' . $i . $ext;
        }
        return $name;
    }

    /**
     * Write through a temp file in the same folder and rename it into place, so the web server
     * never serves a half-written file. World-readable: the web server may run as another user
     * than PHP (tempnam() creates 0600).
     */
    protected function writeFile(string $folder, string $path, string $bytes): bool
    {
        $tmp = @tempnam($folder, '.favicons-');
        if ($tmp === false) {
            return false;
        }
        if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || !@chmod($tmp, 0644) || !@rename($tmp, $folder . '/' . $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * The dev/build line for a sync result, or null when there is nothing worth saying (switched
     * off or in dev mode with nothing removed).
     *
     * @param array<string, mixed> $result
     */
    public function describe(array $result): ?string
    {
        $folder = (string) $result['folder'];
        if ($result['status'] === self::STATUS_UNWRITABLE) {
            return _t(
                __CLASS__ . '.UNWRITABLE',
                'The webroot ({folder}) is not writable, so no icon files are written there; the icons are served through PHP.',
                ['folder' => $folder]
            );
        }
        $parts = [];
        if ($result['written']) {
            $parts[] = _t(__CLASS__ . '.WROTE', 'wrote {files}', ['files' => implode(', ', $result['written'])]);
        }
        if ($result['removed']) {
            $parts[] = _t(__CLASS__ . '.REMOVED', 'removed {files}', ['files' => implode(', ', $result['removed'])]);
        }
        if ($result['current']) {
            $parts[] = _t(__CLASS__ . '.CURRENT', '{count} up to date', ['count' => count($result['current'])]);
        }
        foreach ($result['failed'] as $path => $reason) {
            $parts[] = _t(__CLASS__ . '.FAILED', '{file} skipped ({reason}, served through PHP)', ['file' => $path, 'reason' => $reason]);
        }
        foreach ($result['renamed'] as $path => $backup) {
            $parts[] = _t(
                __CLASS__ . '.RENAMED',
                'renamed the existing {file} to {backup}: remove {file} from your project (eg its repository), this module writes it now',
                ['file' => $path, 'backup' => $backup]
            );
        }
        if (!$parts) {
            return null;
        }
        return _t(__CLASS__ . '.SUMMARY', 'Icon files in the webroot ({folder}): {parts}.', ['folder' => $folder, 'parts' => implode('; ', $parts)]);
    }

    protected function log(string $level, string $message): void
    {
        try {
            Injector::inst()->get(LoggerInterface::class)->log($level, $message);
        } catch (Throwable $e) {
            # Logging must not break a dev/build or a Settings save.
        }
    }
}
