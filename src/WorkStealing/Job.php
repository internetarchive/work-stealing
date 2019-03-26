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

/**
 *
 */
interface Job
{
  /** @var int Indicates the enlisted process performed a significant amount of work */
  const RECRUITED = 1;

  /** @var int Indicates the enlisted process did not perform a significant amount of work */
  const DISMISSED = 0;

  /**
   * @return int \WorkStealing\Job::RECRUITED or \WorkStealing\Job::DISMISSED
   */
  public function recruited();
}
