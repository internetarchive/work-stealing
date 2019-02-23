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

namespace Kleptes;

/**
 *
 */
class Recruiter
{
  /** @var \Kleptes\Recruiter|null */
  private static $master = null;

  /** @var \Kleptes\Job[] */
  private $jobs = [];

  /** @var \Kleptes\Random */
  private $rand;

  /**
   *
   */
  public function __construct(Random $rand = null)
  {
    $this->rand = $rand ?: new MtRandom();
  }

  /**
   * @return \Kleptes\Recruiter
   */
  public static function master()
  {
    if (!isset(static::$master))
      static::$master = new self();

    return static::$master;
  }

  /**
   * @return void
   */
  public function install(Job $job, string $job_id, float $rate)
  {
    if (isset($this->jobs[$job_id]))
      throw new Exception("Job \"$job_id\" already registered");

    $this->jobs[$job_id] = [ $job, $rate ];
  }

  /**
   * @return int \Kleptes\Job::RECRUITED or \Kleptes\Job::DISMISSED
   */
  public function enlist()
  {
  }

  /**
   * @return int \Kleptes\Job::RECRUITED or \Kleptes\Job::DISMISSED
   */
  public function volunteer()
  {
  }
}
