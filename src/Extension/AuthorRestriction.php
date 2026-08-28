<?php

/**
 * @package     OpenSAI
 * @subpackage  plg_filesystem_authorrestriction
 *
 * @copyright   Copyright (C) 2026 Open Source Academic Initiative (OpenSAI). All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace OpenSAI\Plugin\Filesystem\AuthorRestriction\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Media\Administrator\Event\MediaProviderEvent;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use OpenSAI\Plugin\Filesystem\AuthorRestriction\Provider\RestrictedProviderDecorator;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Confines the Media Manager (standalone and the article field picker) to a
 * user's own folder (named after their user ID) unless they are a
 * Super User or a member of the "Administrator" group, who keep the full tree.
 *
 * Works by re-wrapping every already-registered media provider (e.g. the core
 * "Filesystem - Local" plugin) with a decorator that enforces the path
 * boundary on every adapter call. No core files are touched, so it survives
 * Joomla upgrades; disabling this plugin fully restores the stock behaviour.
 *
 * @since  1.0.0
 */
final class AuthorRestriction extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * @return  array
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onSetupProviders' => 'onSetupProviders',
        ];
    }

    /**
     * Runs after the built-in filesystem plugins have registered their
     * providers (this plugin is installed after them, so it gets a higher
     * default ordering within the "filesystem" group and fires last).
     *
     * @param   MediaProviderEvent  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onSetupProviders(MediaProviderEvent $event): void
    {
        $ownRoot = $this->resolveOwnRootOrNull();

        if ($ownRoot === null) {
            // Super User or Administrator: leave every provider untouched.
            return;
        }

        $manager = $event->getProviderManager();

        foreach ($manager->getProviders() as $provider) {
            $manager->unregisterProvider($provider);
            $manager->registerProvider(new RestrictedProviderDecorator($provider, $ownRoot));
        }
    }

    /**
     * Returns the media path the current user must be confined to
     * (e.g. "/123"), or null when the user should see the full tree.
     *
     * @return  string|null
     *
     * @since   1.0.0
     */
    private function resolveOwnRootOrNull(): ?string
    {
        $user = $this->getApplication()->getIdentity();

        if (!$user || $user->guest) {
            return null;
        }

        // Super Users always pass core.admin — this is the standard Joomla check.
        if ($user->authorise('core.admin')) {
            return null;
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('g.title'))
            ->from($db->quoteName('#__usergroups', 'g'))
            ->join(
                'INNER',
                $db->quoteName('#__user_usergroup_map', 'm'),
                $db->quoteName('m.group_id') . ' = ' . $db->quoteName('g.id')
            )
            ->where($db->quoteName('m.user_id') . ' = :userId')
            ->bind(':userId', $user->id, ParameterType::INTEGER);

        $groupTitles = $db->setQuery($query)->loadColumn() ?: [];

        if (\in_array('Administrator', $groupTitles, true) || \in_array('Super Users', $groupTitles, true)) {
            return null;
        }

        return '/' . (int) $user->id;
    }
}
