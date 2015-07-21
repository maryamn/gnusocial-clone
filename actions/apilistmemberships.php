<?php
/**
 * StatusNet, the distributed open-source microblogging tool
 *
 * Get a list of lists a user belongs to. (people tags for a user)
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
 * @category  API
 * @package   StatusNet
 * @author    Shashi Gowda <connect2shashi@gmail.com>
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://status.net/
 */

if (!defined('STATUSNET')) {
    exit(1);
}

/**
 * Action handler for API method to list lists a user belongs to.
 * (people tags for a user)
 *
 * @category API
 * @package  StatusNet
 * @author   Shashi Gowda <connect2shashi@gmail.com>
 * @license  http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link     http://status.net/
 * @see      ApiBareAuthAction
 */
class ApiListMembershipsAction extends ApiBareAuthAction
{
    var $lists = array();
    var $cursor = -1;
    var $next_cursor = 0;
    var $prev_cursor = 0;

    /**
     * Prepare for running the action
     * Take arguments for running:s
     *
     * @param array $args $_REQUEST args
     *
     * @return boolean success flag
     *
     */
    protected function prepare(array $args=array())
    {
        parent::prepare($args);

        $this->cursor = (int) $this->arg('cursor', -1);
        $user = $this->getTargetUser($this->arg('user'));

        if (!($user instanceof User)) {
            // TRANS: Client error displayed trying to perform an action related to a non-existing user.
            $this->clientError(_('No such user.'), 404);
        }
        $this->target = $user->getProfile();

        $this->getLists();

        return true;
    }

    /**
     * Handle the request
     *
     * Show the lists
     *
     * @return void
     */
    protected function handle()
    {
        parent::handle();

        switch($this->format) {
        case 'xml':
            $this->showXmlLists($this->lists, $this->next_cursor, $this->prev_cursor);
            break;
        case 'json':
            $this->showJsonLists($this->lists, $this->next_cursor, $this->prev_cursor);
            break;
        default:
            // TRANS: Client error displayed when coming across a non-supported API method.
            $this->clientError(_('API method not found.'));
        }
    }

    /**
     * Return true if read only.
     *
     * MAY override
     *
     * @param array $args other arguments
     *
     * @return boolean is read only action?
     */
    function isReadOnly($args)
    {
        return true;
    }

    function getLists()
    {
        $profile = $this->target;
        $fn = array($profile, 'getOtherTags');

        # 20 lists
        list($this->lists, $this->next_cursor, $this->prev_cursor) =
                Profile_list::getAtCursor($fn, array($this->auth_user), $this->cursor, 20);
    }
}
