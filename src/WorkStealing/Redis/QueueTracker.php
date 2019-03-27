<?php

/**
 * Copyright (C) 2019 Internet Archive
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace WorkStealing\Redis;

use WorkStealing\Job;
use WorkStealing\Random\MtRandom;
use Predis\Client;

/**
 * Track identifiers on a work queue in Redis.
 *
 * When tasks are stored in a queue, one or more tasks per identifier, this class will cache those
 * identifiers in a Redis set so it can be quickly determined if work is pending for them.
 *
 * The constructor accepts two callbacks:
 *
 * $is_queued_cb() which queries the task queue/database to see if the supplied list of identifiers
 * still has pending work.
 *
 * $next_queued_cb() scrapes the task queue/database for the next round of tasks waiting in the
 * queue.  The function accepts the last rowid (to continue the scrape where it last left off) and
 * the maximum number of identifiers to return.  The last rowid is a reference; the callback should
 * set it to the rowid where the scrape should pick up the next time the callback is invoked.
 *
 * $next_queued_cb() is free to return identical identifiers if multiple tasks are outstanding; the
 * Redis set will only store one copy of the string.
 *
 * When this class is installed in Recruiter, it will continuously scrape the task queue and maintain
 * a roughly current set of identifiers with work pending.
 *
 * @todo If concurrent scrapes & queries are undesirable, a distributed lock could be used to
 * prevent more than one worker accessing the task queue/database at a time.
 */
class QueueTracker implements Job
{
  /** @var int Number of identifiers to work with per job cycle */
  const WORK_COUNT = 25;

  /** @var \Predis\Client */
  private $redis;

  /** @var string */
  private $set_key;

  /** @var string */
  private $last_rowid_key;

  /** @var callable */
  private $is_queued_cb;

  /** @var callable */
  private $next_queued_cb;

  /** @var \WorkStealing\Random\Random */
  private $random;

  /**
   * $is_queued_cb:
   *   is_queued_cb(string[] $ids) : string[]|null
   *
   * $next_queued_cb:
   *   next_queued_cb(int &$last_rowid, int $count) : string[]|null
   *
   * @param \Predis\Client
   * @param string $set_key Redis key for set
   * @param string $last_rowid_key Redis key for string
   * @param callable $is_queued_cb Recompute function for determining if identifier is queued
   * @param callable $next_queued_cb List `n` identifiers in queue after supplied rowid
   */
  public function __construct(
    Client $redis,
    string $set_key,
    string $last_row_id_key,
    callable $is_queued_cb,
    callable $next_queued_cb
  )
  {
    $this->redis = $redis;
    $this->set_key = $set_key;
    $this->last_rowid_key = $last_rowid_key;
    $this->is_queued_cb = $is_queued_cb;
    $this->next_queued_cb = $next_queued_cb;
    $this->random = new MtRandom();
  }

  /**
   * Check if an identifier is queued.
   *
   * @param string $id
   * @return bool
   * @throws \Predis\PredisException
   */
  public function is_queued(string $id)
  {
    return $this->redis->sismember($this->set_key, $id);
  }

  /**
   * @inheritDoc
   */
  public function recruited()
  {
    $added_or_removed = 0;

    $this->redis->watch($this->set_key, $this->last_rowid_key);
    try {
      // half of the recruits add queued identifiers, the other half remove stale ones ... obviously
      // the mechanism correct for your situation is highly dependent on your environment, this is
      // merely a simple approach to illustrate one possibility
      $added_or_removed = ($this->random->next_float() < 0.5) ? $this->add() : $this->remove();
    } catch (\Predis\PredisException $pe) {
      // log error but do not retry or consider fatal ... if transaction aborted because of WATCH,
      // indicates a write occurred and GC should not occur now (next worker will attempt)
      echo $pe->getTraceAsString();
    } finally {
      // clear WATCH (in case transaction did not run)
      $this->redis->unwatch();
    }

    // RECRUITED if any change was made to the Redis set
    return $added_or_removed ? Job::RECRUITED : Job::DISMISSED;
  }

  /**
   * Add the next round of queued tasks to the Redis set.
   *
   * @return int Number of added identifiers
   * @throws \Predis\PredisException
   */
  private function add()
  {
    // GET: get the rowid from the last successful job
    $last_rowid = $this->redis->get($this->last_rowid_key);
    if ($last_rowid === null)
      $last_rowid = 0;

    // use the callback to fetch the next WORK_COUNT identifiers from the task queue ... the
    // callback is expected to accept $last_rowid as a reference and set it to the highest rowid
    // for the next iteration
    $new_ids = call_user_func($this->next_queued_cb, $last_rowid, self::WORK_COUNT);
    if (empty($new_ids))
      return 0;

    $xact = $this->redis->transaction();

    // SADD: Add the newest identifiers to the Redis set
    $xact->sadd($this->set_key, $new_ids);

    // SET: set the rowid to start from for the next job
    $xact->set($this->last_rowid_id, $last_rowid);

    // if the set or the last rowid key were disturbed, this will abort and it'll be up to the
    // next recruit to add these identifiers
    $results = $xact->execute();

    // return the result of SADD
    return $result[0];
  }

  /**
   * Remove tasks no longer queued from the Redis set.
   *
   * @return int Number of removed identifiers
   * @throws \Predis\PredisException
   */
  private function remove()
  {
    // SRANDMEMBER: fetch `n` random members marked as queued in the Redis set
    $queued_ids = $this->redis->srandmember($this->set_key, self::WORK_COUNT);
    if (empty($queued_ids))
      return 0;

    // determine which of the sampled identifiers still has work queued
    $not_queued_ids = call_user_func($this->is_queued_cb, $queued_ids);
    if (empty($not_queued_ids))
      return 0;

    $xact = $this->redis->transaction();

    // SREM: remove all the identifiers no longer on the work queue
    $xact->srem($this->set_key, $not_queued_ids);

    // if the set or last rowid were disturbed, this will abort and the next recruit wll have to
    // try again
    $result = $xact->execute();

    // return the result of SREM
    return $result[0];
  }
}
