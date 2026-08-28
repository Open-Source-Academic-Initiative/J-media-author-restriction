<?php

/**
 * @package     OpenSAI
 * @subpackage  plg_filesystem_authorrestriction
 *
 * @copyright   Copyright (C) 2026 Open Source Academic Initiative (OpenSAI). All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace OpenSAI\Plugin\Filesystem\AuthorRestriction\Provider;

use Joomla\Component\Media\Administrator\Provider\ProviderInterface;
use OpenSAI\Plugin\Filesystem\AuthorRestriction\Adapter\RestrictedAdapterDecorator;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Wraps a real media provider (e.g. "Filesystem - Local") so every adapter it
 * hands out is confined to one folder. Keeps the wrapped provider's own ID,
 * so nothing downstream (adapter names like "local-images", the Media
 * Manager UI, the article field picker) needs to know it's been wrapped.
 *
 * @since  1.0.0
 */
final class RestrictedProviderDecorator implements ProviderInterface
{
    /**
     * @param   ProviderInterface  $realProvider  The provider being wrapped.
     * @param   string             $ownRoot       The path the user is confined to, e.g. "/123".
     *
     * @since   1.0.0
     */
    public function __construct(private readonly ProviderInterface $realProvider, private readonly string $ownRoot)
    {
    }

    /**
     * @return  string
     *
     * @since   1.0.0
     */
    public function getID()
    {
        return $this->realProvider->getID();
    }

    /**
     * @return  string
     *
     * @since   1.0.0
     */
    public function getDisplayName()
    {
        return $this->realProvider->getDisplayName();
    }

    /**
     * @return  \Joomla\Component\Media\Administrator\Adapter\AdapterInterface[]
     *
     * @since   1.0.0
     */
    public function getAdapters()
    {
        $adapters = [];

        foreach ($this->realProvider->getAdapters() as $name => $adapter) {
            $restricted = new RestrictedAdapterDecorator($adapter, $this->ownRoot);
            $restricted->ensureOwnFolderExists();

            $adapters[$name] = $restricted;
        }

        return $adapters;
    }
}
