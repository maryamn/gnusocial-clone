<?php
/**
 * StatusNet, the distributed open-source microblogging tool
 *
 * Class for an exception when a Profile has no discernable acct: URI
 *
 * PHP version 5
 *
 * LICENCE: This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @category  Exception
 * @package   StatusNet
 * @author    Mikael Nordfeldth <mmn@hethane.se>
 * @copyright 2013 Free Software Foundation, Inc. 
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html AGPLv3
 * @link      http://www.gnu.org/software/social/
 */

if (!defined('GNUSOCIAL')) { exit(1); }

/**
 * Parent class for an exception when a profile is missing
 *
 * @category Exception
 * @package  StatusNet
 * @author   Evan Prodromou <evan@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl-3.0.html AGPLv3
 * @link     http://status.net/
 */

class ProfileNoAcctUriException extends ServerException
{
    public $profile = null;

    public function __construct(Profile $profile, $msg=null)
    {
        $this->profile = $profile;

        if ($msg === null) {
            // TRANS: Exception text shown when no profile can be found for a user.
            // TRANS: %1$s is a user nickname, $2$d is a user ID (number).
            $msg = sprintf(_('Could not get an acct: URI for profile with id==%u'), $this->profile->id);
        }

        parent::__construct($msg);
    }
}
