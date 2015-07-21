<?php
/**
 * GNU social queue-manager-on-visit class
 *
 * Will run events for a certain time, or until finished.
 *
 * Configure remote key if wanted with $config['opportunisticqm']['qmkey'] and
 * use with /main/runqueue?qmkey=abc123
 *
 * @category  Cron
 * @package   GNUsocial
 * @author    Mikael Nordfeldth <mmn@hethane.se>
 * @copyright 2013 Free Software Foundation, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html AGPL 3.0
 * @link      http://status.net/
 */
class OpportunisticQueueManager extends DBQueueManager
{
    protected $qmkey = false;
    protected $max_execution_time = null;
    protected $max_queue_items = null;

    protected $started_at = null;
    protected $handled_items = 0;

    const MAXEXECTIME = 30; // typically just used for the /main/cron action

    public function __construct(array $args=array()) {
        foreach (get_class_vars(get_class($this)) as $key=>$val) {
            if (array_key_exists($key, $args)) {
                $this->$key = $args[$key];
            }
        }
        $this->verifyKey();

        if ($this->started_at === null) {
            $this->started_at = time();
        }

        if ($this->max_execution_time === null) {
            $this->max_execution_time = ini_get('max_execution_time') ?: self::MAXEXECTIME;
        }

        return parent::__construct();
    }

    protected function verifyKey()
    {
        if ($this->qmkey !== common_config('opportunisticqm', 'qmkey')) {
            throw new RunQueueBadKeyException($this->qmkey);
        }
    }

    public function canContinue()
    {
        $time_passed = time() - $this->started_at;
        
        // Only continue if limit values are sane
        if ($time_passed <= 0 && (!is_null($this->max_queue_items) && $this->max_queue_items <= 0)) {
            return false;
        }
        // If too much time has passed, stop
        if ($time_passed >= $this->max_execution_time) {
            return false;
        }
        // If we have a max-item-limit, check if it has been passed
        if (!is_null($this->max_queue_items) && $this->handled_items >= $this->max_queue_items) {
            return false;
        }

        return true;
    }

    public function poll()
    {
        $this->handled_items++;
        if (!parent::poll()) {
            throw new RunQueueOutOfWorkException();
        }
        return true;
    }

    // OpportunisticQM shouldn't discard items it can't handle, we're
    // only here to take care of what we _can_ handle!
    protected function noHandlerFound(Queue_item $qi, $rep=null) {
        $this->_log(LOG_WARNING, "[{$qi->transport}:item {$qi->id}] Releasing claim for queue item without a handler");
        $this->_fail($qi, true);    // true here means "releaseOnly", so no error statistics since it's not an _error_
    }

    /**
     * Takes care of running through the queue items, returning when
     * the limits setup in __construct are met.
     *
     * @return true on workqueue finished, false if there are still items in the queue
     */
    public function runQueue()
    {
        while ($this->canContinue()) {
            try {
                $this->poll();
            } catch (RunQueueOutOfWorkException $e) {
                return true;
            }
        }
        common_debug('Opportunistic queue manager passed execution time/item handling limit without being out of work.');
        return false;
    }
}
