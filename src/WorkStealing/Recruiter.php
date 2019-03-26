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

namespace WorkStealing;

use InvalidArgumentException;
use OutOfBoundsException;
use WorkStealing\Random\MtRandom;
use WorkStealing\Random\Random;

/**
 *
 */
class Recruiter
{
  /** @var \WorkStealing\Recruiter|null */
  private static $master = null;

  /** @var \WorkStealing\Job[] */
  private $jobs = [];

  /** @var \WorkStealing\Random\Random */
  private $random;

  /**
   *
   */
  public function __construct(Random $random = null)
  {
    $this->random = $random ?: new MtRandom();
  }

  /**
   * @return \WorkStealing\Recruiter
   */
  public static function master()
  {
    if (!isset(static::$master))
      static::$master = new self();

    return static::$master;
  }

  /**
   * @throws \InvalidArgumentException If $job_id has already been installed
   * @throws \OutOfBoundsException If $rate will put the total of all installed Jobs over 1.0
   */
  public function install(Job $job, string $job_id, float $rate)
  {
    if (isset($this->jobs[$job_id]))
      throw new InvalidArgumentException("Job \"$job_id\" already registered");

    // sum recruiting rates with addition, watch for overflow
    $total = array_reduce($this->jobs, function ($sum, $details) {
      return $sum + $details['rate'];
    }, $rate);
    if ($total > 1.0)
      throw new OutOfBoundsException("Job \"$job_id\" recruiting rate $rate will exceed max. rate of 1.0");

    $this->jobs[$job_id] = [ 'job' => $job, 'rate' => $rate ];
  }

  /**
   * @return int \WorkStealing\Job::RECRUITED or \WorkStealing\Job::DISMISSED
   */
  public function enlist()
  {
    $duty = Job::DISMISSED;

    $job = $this->select_job();
    if ($job)
      $duty = $this->work($job);

    return $duty;
  }

  /**
   * @return string[] Job ID's reporting Job::RECRUITED
   */
  public function volunteer()
  {
    $recruited = [];
    foreach ($this->jobs as $job_id => $details) {
      if ($this->work($details['job']) == Job::RECRUITED)
        $recruited[] = $job_id;
    }

    return $recruited;
  }

  /**
   * Randomly select a Job to perform work on.
   *
   * If caller avoided duty, returns `null`.
   *
   * @return \WorkStealing\Job|null
   */
  private function select_job()
  {
    $lottery = $this->random->next_float();

    $target = 0.0;
    foreach ($this->jobs as $job_id => $details) {
      $target += $details['rate'];

      // chance of being selected accumulates with each Job to ensure each Job is getting its
      // requested percentage of recruits ... see README.md for rationale
      if ($lottery <= $target)
        return $details['job'];
    }

    return null;
  }

  /**
   * Let a Job perform some work.
   *
   * @param \WorkStealing\Job $job
   * @return int \WorkStealing\Job::RECRUITED or \WorkStealing\Job::DISMISSED
   */
  private function work(Job $job)
  {
    // default is RECRUITED if Exception is thrown
    $duty = Job::RECRUITED;
    try {
      $duty = $job->recruited();
    } catch (Exception $e) {
      // TODO: log error
      $e->printStackTrace();
    }

    // if Job forgot to return a value, assume enlistee was recruited
    return isset($duty) ? $duty : Job::RECRUITED;
  }
}
