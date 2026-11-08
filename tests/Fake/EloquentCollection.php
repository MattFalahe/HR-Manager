<?php

namespace HrManager\Tests\Fake;

use Illuminate\Support\Collection;

/**
 * Eloquent's collection is Support's with model-specific extras HR does not
 * use, so the real Support collection serves.
 */
class EloquentCollection extends Collection
{
}
