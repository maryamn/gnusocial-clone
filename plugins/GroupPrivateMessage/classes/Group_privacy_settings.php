<?php
/**
 * Data class for group privacy settings
 *
 * PHP version 5
 *
 * @category Data
 * @package  StatusNet
 * @author   Evan Prodromou <evan@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl.html AGPLv3
 * @link     http://status.net/
 *
 * StatusNet - the distributed open-source microblogging tool
 * Copyright (C) 2011, StatusNet, Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.     See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

if (!defined('STATUSNET')) {
    exit(1);
}

/**
 * Data class for group privacy
 *
 * Stores admin preferences about the group.
 *
 * @category Action
 * @package  StatusNet
 * @author   Evan Prodromou <evan@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl.html AGPLv3
 * @link     http://status.net/
 *
 * @see      DB_DataObject
 */
class Group_privacy_settings extends Managed_DataObject
{
    public $__table = 'group_privacy_settings';
    /** ID of the group. */
    public $group_id;
    /** When to allow privacy: always, sometimes, or never. */
    public $allow_privacy;
    /** Who can send private messages: everyone, member, admin */
    public $allow_sender;
    /** row creation timestamp */
    public $created;
    /** Last-modified timestamp */
    public $modified;

    /** NEVER is */

    const SOMETIMES = -1;
    const NEVER  = 0;
    const ALWAYS = 1;

    /** These are bit-mappy, as a hedge against the future. */

    const EVERYONE = 1;
    const MEMBER   = 2;
    const ADMIN    = 4;

    public static function schemaDef()
    {
        return array(
            'fields' => array(
                'group_id' => array('type' => 'int', 'not null' => true, 'description' => 'group_privacy_settings'),
                'allow_privacy' => array('type' => 'int', 'not null' => true, 'description' => 'sometimes=-1, never=0, always=1'),
                'allow_sender' => array('type' => 'int', 'not null' => true, 'description' => 'list of bit-mappy values in source code'),
                'created' => array('type' => 'datetime', 'not null' => true, 'description' => 'date this record was created'),
                'modified' => array('type' => 'timestamp', 'not null' => true, 'description' => 'date this record was modified'),
            ),
            'primary key' => array('group_id'),
            'foreign keys' => array(
                'group_privacy_settings_group_id_fkey' => array('user_group', array('group_id' => 'id')),
            ),
        );
    }

    function forGroup($group)
    {
        $gps = Group_privacy_settings::getKV('group_id', $group->id);

        if (empty($gps)) {
            // make a fake one with defaults
            $gps = new Group_privacy_settings();
            $gps->allow_privacy = Group_privacy_settings::SOMETIMES;
            $gps->allow_sender  = Group_privacy_settings::MEMBER;
        }

        return $gps;
    }

    function ensurePost($user, $group)
    {
        $gps = self::forGroup($group);

        if ($gps->allow_privacy == Group_privacy_settings::NEVER) {
            // TRANS: Exception thrown when trying to set group privacy setting if group %s does not allow private messages.
            throw new Exception(sprintf(_m('Group %s does not allow private messages.'),
                                        $group->nickname));
        }

        switch ($gps->allow_sender) {
        case Group_privacy_settings::EVERYONE:
            $profile = $user->getProfile();
            if (Group_block::isBlocked($group, $profile)) {
                // TRANS: Exception thrown when trying to send group private message while blocked from that group.
                // TRANS: %1$s is a user nickname, %2$s is a group nickname.
                throw new Exception(sprintf(_m('User %1$s is blocked from group %2$s.'),
                                            $user->nickname,
                                            $group->nickname));
            }
            break;
        case Group_privacy_settings::MEMBER:
            if (!$user->isMember($group)) {
                // TRANS: Exception thrown when trying to send group private message while not a member.
                // TRANS: %1$s is a user nickname, %2$s is a group nickname.
                throw new Exception(sprintf(_m('User %1$s is not a member of group %2$s.'),
                                            $user->nickname,
                                            $group->nickname));
            }
            break;
        case Group_privacy_settings::ADMIN:
            if (!$user->isAdmin($group)) {
                // TRANS: Exception thrown when trying to send group private message while not a group administrator.
                // TRANS: %1$s is a user nickname, %2$s is a group nickname.
                throw new Exception(sprintf(_m('User %1$s is not an administrator of group %2$s.'),
                                            $user->nickname,
                                            $group->nickname));
            }
            break;
        default:
            // TRANS: Exception thrown when encountering undefined group privacy settings.
            // TRANS: %s is a group nickname.
            throw new Exception(sprintf(_m('Unknown privacy settings for group %s.'),
                                        $group->nickname));
        }

        return true;
    }
}
