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

namespace Kleptes\Random;

/**
 * Random or pseudo-random number generator.
 */
abstract class Random
{
  /**
   * @return void
   */
  public abstract function seed(int $seed = null);

  /**
   * @return int
   */
  public abstract function max_int();

  /**
   * @return int
   */
  public abstract function next_int(int $max = null);

  /**
   * @return float Between 0.0 (inclusive) and 1.0 (exclusive).
   */
  public function next_float()
  {
    return floatval($this->next_int()) / floatval($this->max_int());
  }
}
