<?php
/**
 * Data class for nickname blacklisting
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
 * Copyright (C) 2009, StatusNet, Inc.
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

require_once INSTALLDIR . '/classes/Memcached_DataObject.php';

/**
 * Data class for Nickname blacklist
 *
 * @category Action
 * @package  StatusNet
 * @author   Evan Prodromou <evan@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl.html AGPLv3
 * @link     http://status.net/
 *
 * @see      DB_DataObject
 */
class Nickname_blacklist extends Managed_DataObject
{
    public $__table = 'nickname_blacklist'; // table name
    public $pattern;                        // varchar(191) pattern   not 255 because utf8mb4 takes more space
    public $created;                        // datetime not_null
    public $modified;                       // timestamp()   not_null default_CURRENT_TIMESTAMP

    public static function schemaDef()
    {
        return array(
            'fields' => array(
                'pattern' => array('type' => 'varchar', 'not null' => true, 'length' => 191, 'description' => 'blacklist pattern'),
                'created' => array('type' => 'datetime', 'not null' => true, 'description' => 'date this record was created'),
                'modified' => array('type' => 'timestamp', 'not null' => true, 'description' => 'date this record was modified'),
            ),
            'primary key' => array('pattern'),
        );
    }

    /**
     * Return a list of patterns to check
     *
     * @return array string patterns to check
     */
    static function getPatterns()
    {
        $patterns = self::cacheGet('nickname_blacklist:patterns');

        if ($patterns === false) {

            $patterns = array();

            $nb = new Nickname_blacklist();

            $nb->find();

            while ($nb->fetch()) {
                $patterns[] = $nb->pattern;
            }

            self::cacheSet('nickname_blacklist:patterns', $patterns);
        }

        return $patterns;
    }

    /**
     * Save new list of patterns
     *
     * @return array of patterns to check
     */
    static function saveNew($newPatterns)
    {
        $oldPatterns = self::getPatterns();

        // Delete stuff that's old that not in new
        $toDelete = array_diff($oldPatterns, $newPatterns);

        // Insert stuff that's in new and not in old
        $toInsert = array_diff($newPatterns, $oldPatterns);

        foreach ($toDelete as $pattern) {
            $nb = Nickname_blacklist::getKV('pattern', $pattern);
            if (!empty($nb)) {
                $nb->delete();
            }
        }

        foreach ($toInsert as $pattern) {
            $nb = new Nickname_blacklist();
            $nb->pattern = $pattern;
            $nb->created = common_sql_now();
            $nb->insert();
        }

        self::blow('nickname_blacklist:patterns');
    }

    static function ensurePattern($pattern)
    {
        $nb = Nickname_blacklist::getKV('pattern', $pattern);

        if (empty($nb)) {
            $nb = new Nickname_blacklist();
            $nb->pattern = $pattern;
            $nb->created = common_sql_now();
            $nb->insert();
            self::blow('nickname_blacklist:patterns');
        }
    }
}
