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

namespace Kleptes\Redis;

use Kleptes\Job;
use Predis\Client;

/**
 *
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
   *
   */
  public function __construct(Client $redis, string $base_key)
  {
    $this->redis = $redis;
    $this->hash_key = "vhash-map:{$base_key}";
    $this->zset_key = "vhash-zset:{$base_key}";
  }

  /**
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
   *
   */
  public function hget(string $field, string $value)
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
    } finally {
      // clear WATCH (in case transaction did not run)
      $this->redis->unwatch();
    }

    return $reaped ? Job::RECRUITED : Job::DISMISSED;
  }

  /**
   *
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
