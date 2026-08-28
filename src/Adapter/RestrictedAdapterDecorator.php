<?php

/**
 * @package     OpenSAI
 * @subpackage  plg_filesystem_authorrestriction
 *
 * @copyright   Copyright (C) 2026 Open Source Academic Initiative (OpenSAI). All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace OpenSAI\Plugin\Filesystem\AuthorRestriction\Adapter;

use Joomla\CMS\Language\Text;
use Joomla\Component\Media\Administrator\Adapter\AdapterInterface;
use Joomla\Component\Media\Administrator\Exception\FileNotFoundException;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Wraps a real media adapter and presents one of its folders (e.g. "/123") as
 * if it were the adapter's own root: every path the caller passes in is
 * user-space, relative to that virtual root, and every path this class hands
 * back out (in `getFile`/`getFiles`/`search` results, and the `move`/`copy`
 * return value) is translated back into that same user-space — per
 * `AdapterInterface`'s own contract that a returned `path` is "the relative
 * path to the root". Returning the wrapped adapter's real, `data/`-rooted
 * paths instead (an earlier version of this class did) breaks the Media
 * Manager's UI: it requests "/", so its client-side state treats "/" as the
 * current directory, and every item it gets back claiming to live under
 * "/123/…" doesn't match — the folder opens but renders empty.
 *
 * Because every real path is *computed* by this class (virtual path,
 * normalized, prefixed with the real root) rather than taken from caller
 * input and merely checked, there is no separate boundary check to bypass:
 * a virtual path can only ever resolve under the real root.
 *
 * @since  1.0.0
 */
final class RestrictedAdapterDecorator implements AdapterInterface
{
    /**
     * @param   AdapterInterface  $real     The real adapter being wrapped.
     * @param   string            $ownRoot  The real path this decorator presents as "/", e.g. "/123".
     *
     * @since   1.0.0
     */
    public function __construct(private readonly AdapterInterface $real, private readonly string $ownRoot)
    {
    }

    /**
     * Creates the user's own folder the first time it's needed (new users,
     * or users promoted into a restricted group, don't have one yet).
     * Best-effort: a failure here surfaces normally on first real use.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function ensureOwnFolderExists(): void
    {
        try {
            $this->real->getFile($this->ownRoot);
        } catch (\Exception) {
            try {
                $this->real->createFolder(\ltrim($this->ownRoot, '/'), '/');
            } catch (\Exception) {
                // Ignore — e.g. a race with another request creating it first.
            }
        }
    }

    /**
     * Collapses "." and ".." segments in a caller-supplied virtual path
     * before it's turned into a real one, so a traversal sequence (e.g.
     * "/../secret") can't resolve outside the boundary once real-rooted.
     * Anything that tries to climb above the virtual root just flattens to
     * it — there is nothing above "/" to escape to in virtual space.
     *
     * @param   string  $path  The virtual path as supplied by the caller.
     *
     * @return  string  The normalized virtual path (always starts with "/").
     *
     * @since   1.0.0
     */
    private function normalize(string $path): string
    {
        $segments = \explode('/', \str_replace('\\', '/', $path));
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                \array_pop($resolved);
                continue;
            }

            $resolved[] = $segment;
        }

        return '/' . \implode('/', $resolved);
    }

    /**
     * Translates a caller-supplied virtual path (relative to this decorator's
     * own presented root) into the real path to pass to the wrapped adapter.
     *
     * @param   string  $virtualPath  The path as supplied by the caller.
     *
     * @return  string  The real, `data/`-rooted path.
     *
     * @since   1.0.0
     */
    private function toRealPath(string $virtualPath): string
    {
        $normalized = $this->normalize($virtualPath);

        return $normalized === '/' ? $this->ownRoot : $this->ownRoot . $normalized;
    }

    /**
     * Translates a real, `data/`-rooted path — as returned by the wrapped
     * adapter — back into the virtual path this decorator presents to its
     * caller. The inverse of {@see toRealPath()}.
     *
     * @param   string  $realPath  The real path, as returned by the wrapped adapter.
     *
     * @return  string  The virtual path (relative to this decorator's own root).
     *
     * @throws  FileNotFoundException  When the real path unexpectedly falls outside the user's folder.
     *
     * @since   1.0.0
     */
    private function toVirtualPath(string $realPath): string
    {
        if ($realPath === $this->ownRoot) {
            return '/';
        }

        if (\str_starts_with($realPath, $this->ownRoot . '/')) {
            return \substr($realPath, \strlen($this->ownRoot));
        }

        // The wrapped adapter is only ever asked for paths under $ownRoot (via
        // toRealPath()), so this would mean the real adapter returned something
        // it was never asked for — treat it the same as a missing file rather
        // than leak a real path outside the boundary.
        throw new FileNotFoundException(Text::_('COM_MEDIA_ERROR_FILE_NOT_FOUND'));
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function getFile(string $path = '/'): \stdClass
    {
        $file       = $this->real->getFile($this->toRealPath($path));
        $file->path = $this->toVirtualPath($file->path);

        return $file;
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function getFiles(string $path = '/'): array
    {
        $files = $this->real->getFiles($this->toRealPath($path));

        foreach ($files as $file) {
            $file->path = $this->toVirtualPath($file->path);
        }

        return $files;
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function getResource(string $path)
    {
        return $this->real->getResource($this->toRealPath($path));
    }

    /**
     * @inheritDoc
     *
     * Unlike {@see move()}/{@see copy()}, the interface documents this as
     * returning "the new folder name" — a bare name, not a path (the real
     * adapter may rename it, e.g. for sanitization, but never returns it
     * path-qualified) — so there is nothing here to translate.
     *
     * @since   1.0.0
     */
    public function createFolder(string $name, string $path): string
    {
        return $this->real->createFolder($name, $this->toRealPath($path));
    }

    /**
     * @inheritDoc
     *
     * Unlike {@see move()}/{@see copy()}, the interface documents this as
     * returning "the new file name" — a bare name, not a path — so there is
     * nothing here to translate. Passing this through {@see toVirtualPath()}
     * (an earlier version of this method did) always threw: a bare filename
     * never starts with the user's real folder, so a successful upload still
     * surfaced as COM_MEDIA_ERROR_FILE_NOT_FOUND to the UI even though the
     * file was written correctly (visible again after a refresh, since
     * {@see getFiles()} was never affected).
     *
     * @since   1.0.0
     */
    public function createFile(string $name, string $path, $data): string
    {
        return $this->real->createFile($name, $this->toRealPath($path), $data);
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function updateFile(string $name, string $path, $data)
    {
        $this->real->updateFile($name, $this->toRealPath($path), $data);
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function delete(string $path)
    {
        $this->real->delete($this->toRealPath($path));
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function move(string $sourcePath, string $destinationPath, bool $force = false): string
    {
        return $this->toVirtualPath(
            $this->real->move($this->toRealPath($sourcePath), $this->toRealPath($destinationPath), $force)
        );
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function copy(string $sourcePath, string $destinationPath, bool $force = false): string
    {
        return $this->toVirtualPath(
            $this->real->copy($this->toRealPath($sourcePath), $this->toRealPath($destinationPath), $force)
        );
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function getUrl(string $path): string
    {
        return $this->real->getUrl($this->toRealPath($path));
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function getAdapterName(): string
    {
        return $this->real->getAdapterName();
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function search(string $path, string $needle, bool $recursive = false): array
    {
        $files = $this->real->search($this->toRealPath($path), $needle, $recursive);

        foreach ($files as $file) {
            $file->path = $this->toVirtualPath($file->path);
        }

        return $files;
    }
}
