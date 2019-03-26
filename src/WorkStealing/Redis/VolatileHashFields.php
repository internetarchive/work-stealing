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
use Predis\Client;

/**
 * Example for using work stealing with Redis: A hash map with volatile fields.
 *
 * With Redis, only keys may have an expiration.  This means individual fields in a hash map cannot
 * be selectively expired.  When the time-to-live is reached, the entire hash map is evicted from
 * the Redis server.
 *
 * VolatileHashFields stores a Redis hash map with no expiration (meaning it won't be evicted when
 * under memory pressure) and stores expiration times for each of its fields in a separate sorted
 * set.  The accessor functions (`hsetex()`, `hget()`) read & write from both data structures.
 *
 * To prevent hash maps with expiring fields from accumulating, a background work stealing job
 * periodically polls the hash map and removes any fields that may have expired.  This allows for
 * memory to be freed in much the same manner as Redis frees memory of volatile keys.
 *
 * To ensure this background work is performed, register an instance of this object with Recruiter
 * and call Recruiter::enlist() periodically.
 */
class VolatileHashFields implements Job
{
  /** @var \Predis\Client */
  private $redis;

  /** @var string */
  private $hash_key;

  /** @var string */
  private $zset_key;

  /**
   * @param \Predis\Client
   * @param string $base_key Base Redis key for hash map and sorted set
   */
  public function __construct(Client $redis, string $base_key)
  {
    $this->redis = $redis;
    $this->hash_key = "vhash-map:{$base_key}";
    $this->zset_key = "vhash-zset:{$base_key}";
  }

  /**
   * Set a hash field with an expiration.
   *
   * @param string $field Field name
   * @param string $value Field value
   * @param int $ttl Time-to-live (in seconds)
   * @throws \Predis\PredisException
   */
  public function hsetex(string $field, string $value, int $ttl)
  {
    $expires = time() + $ttl;

    $xact = $this->redis->transaction();

    $xact->hset($this->hash_key, $field, $value);
    $xact->zadd($this->zset_key, $expires, $field);

    $xact->execute();
  }

  /**
   * Get a hash field value.
   *
   * If the field has expired or is not present, `null` is returned.
   *
   * @param string $field Field name
   * @return string|null
   */
  public function hget(string $field)
  {
    $xact = $this->redis->transaction();

    $xact->hget($this->hash_key, $field);
    $xact->zscore($this->zset_key, $field);

    $results = $xact->execute();

    // don't return if expired
    return $results[1] < time() ? $results[0] : null;
  }

  /**
   * @inheritDoc
   */
  public function recruited()
  {
    $reaped = 0;

    $this->redis->watch($this->hash_key, $this->zset_key);
    try {
      $reaped = $this->gc();
    } catch (\Predis\PredisException $pe) {
      // log error but do not retry or consider fatal ... if transaction aborted because of WATCH,
      // indicates a write occurred and GC should not occur now (next worker will attempt)
      echo $pe->getTraceAsString();
    } finally {
      // clear WATCH (in case transaction did not run)
      $this->redis->unwatch();
    }

    // RECRUITED if one or more fields were reaped from the hash map
    return $reaped ? Job::RECRUITED : Job::DISMISSED;
  }

  /**
   * Perform garbage collection on the hash map.
   *
   * @return int Number of expired fields reaped
   * @throws \Predis\PredisException
   */
  private function gc()
  {
    // get oldest 5 fields past their expiration
    $candidates = $this->redis->zrangebyscore($this->zset_key, '-inf', time(), 'LIMIT', 0, 5);
    if (empty($candidates))
      return 0;

    $xact = $this->redis->transaction();

    $this->hdel($this->hash_key, $candidates);
    $this->zrem($this->zset_key, $candidates);

    $xact->execute();

    return count($candidates);
  }
}
