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
 * Wraps a real media adapter and confines every path argument to one folder
 * (e.g. "/123"). A request for the tree root ("/") is transparently
 * redirected into that folder, so the Media Manager opens straight into it
 * instead of showing (or erroring on) the real root. Any path outside the
 * folder — including as a move/copy source or destination — is rejected the
 * same way a genuinely missing file would be, so the Media Manager UI shows
 * a normal "not found" rather than leaking that a restriction is in place.
 *
 * @since  1.0.0
 */
final class RestrictedAdapterDecorator implements AdapterInterface
{
    /**
     * @param   AdapterInterface  $real     The real adapter being wrapped.
     * @param   string            $ownRoot  The path the user is confined to, e.g. "/123".
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
     * Collapses "." and ".." segments so a boundary check can't be fooled by
     * an unresolved traversal sequence (e.g. "/123/../124/secret.jpg" is
     * "/123/…" as a raw string, but resolves to "/124/secret.jpg"). Joomla's
     * own LocalAdapter only guarantees the result stays under the shared
     * `data/` root — not under our per-user subfolder — so this boundary
     * must resolve the path itself rather than string-prefix-match the raw
     * input.
     *
     * @param   string  $path  The raw path requested by the caller.
     *
     * @return  string  The fully resolved, absolute path (no "." or "..").
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
     * Resolves the effective path for a request and enforces the folder
     * boundary. Requests for "/" (or anything that resolves to it) are
     * redirected into the user's own folder.
     *
     * @param   string  $path  The path requested by the caller.
     *
     * @return  string  The real, resolved path to pass to the wrapped adapter.
     *
     * @throws  FileNotFoundException  When the path falls outside the user's folder.
     *
     * @since   1.0.0
     */
    private function scopedPath(string $path): string
    {
        $normalized = $this->normalize($path);

        if ($normalized === '/') {
            return $this->ownRoot;
        }

        if ($normalized === $this->ownRoot || \str_starts_with($normalized, $this->ownRoot . '/')) {
            return $normalized;
        }

        throw new FileNotFoundException(Text::_('COM_MEDIA_ERROR_FILE_NOT_FOUND'));
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function getFile(string $path = '/'): \stdClass
    {
        return $this->real->getFile($this->scopedPath($path));
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function getFiles(string $path = '/'): array
    {
        return $this->real->getFiles($this->scopedPath($path));
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function getResource(string $path)
    {
        return $this->real->getResource($this->scopedPath($path));
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function createFolder(string $name, string $path): string
    {
        return $this->real->createFolder($name, $this->scopedPath($path));
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function createFile(string $name, string $path, $data): string
    {
        return $this->real->createFile($name, $this->scopedPath($path), $data);
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function updateFile(string $name, string $path, $data)
    {
        $this->real->updateFile($name, $this->scopedPath($path), $data);
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function delete(string $path)
    {
        $this->real->delete($this->scopedPath($path));
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function move(string $sourcePath, string $destinationPath, bool $force = false): string
    {
        return $this->real->move($this->scopedPath($sourcePath), $this->scopedPath($destinationPath), $force);
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function copy(string $sourcePath, string $destinationPath, bool $force = false): string
    {
        return $this->real->copy($this->scopedPath($sourcePath), $this->scopedPath($destinationPath), $force);
    }

    /**
     * @inheritDoc
     *
     * @since   1.0.0
     */
    public function getUrl(string $path): string
    {
        return $this->real->getUrl($this->scopedPath($path));
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
        return $this->real->search($this->scopedPath($path), $needle, $recursive);
    }
}
