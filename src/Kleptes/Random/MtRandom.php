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

use InvalidArgumentException;

/**
 * Random number generator using PHP's built-in Mersenne Twister functions.
 */
class MtRandom extends Random
{
  /**
   * @inheritDoc
   */
  public function seed(int $seed = null)
  {
    isset($seed) ? mt_srand($seed) : mt_srand();
  }

  /**
   * @inheritDoc
   */
  public function max_int()
  {
    return mt_getrandmax();
  }

  /**
   * @inheritDoc
   */
  public function next_int(int $max = null)
  {
    if (isset($max) && ($max <= 0))
      throw new InvalidArgumentException("Maximum value must be positive (supplied: $max)");

    return !isset($max) ? mt_rand() : mt_rand(0, $max);
  }
}
