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

use Exception;
use InvalidArgumentException;
use OutOfBoundsException;
use WorkStealing\Random\MtRandom;
use WorkStealing\Random\Random;

/**
 * A class that manages one or more work stealing Job objects.
 *
 * Jobs are installed with a Recruiter.  Each has a job identifier (string) and a recruiting rate
 * (float).
 *
 * A global instance of Recruiter is provided for convenience.  Different subsystem may invoke their
 * own instances, however.
 *
 * When callers are enlisted, they may or may not be recruited for work using a random number
 * generator and each job's recruiting rate.  If recruited, the caller will be shuttled off to one
 * (1) job, perform a small slice of work, and exit.
 *
 * By performing this work throughout a distributed cluster, background work may be performed without
 * dedicating resources toward daemons, cron jobs, etc.
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
   * @param \WorkStealing\Random\Random|null If `null`, MtRandom is used
   */
  public function __construct(Random $random = null)
  {
    $this->random = $random ?: new MtRandom();
  }

  /**
   * Obtain the global master Recruiter instance.
   *
   * @return \WorkStealing\Recruiter
   */
  public static function master()
  {
    if (!isset(static::$master))
      static::$master = new self();

    return static::$master;
  }

  /**
   * Install a Job with this Recruiter.
   *
   * @param \WorkStealing\Job $job
   * @param string $job_id Each job must have a unique identifier
   * @param float $rate Recruitment rate (percent of enlist() callers to perform this Job)
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
   * Enlist a worker with spare cycles to perform a background job.
   *
   * If returns RECRUITED, the caller performed a small amount of work for a Job.  DISMISSED indicates
   * either it was not recruited or the selected job had no work for it to perform.
   *
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
    // default is DISMISSED if Exception is thrown
    $duty = Job::DISMISSED;
    try {
      $duty = $job->recruited();
    } catch (Exception $e) {
      // TODO: log error
      echo $e->getTraceAsString();
    }

    // if Job forgot to return a value, assume enlistee was recruited
    return isset($duty) ? $duty : Job::RECRUITED;
  }
}
