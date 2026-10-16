<?php

namespace Arzcode\FilamentDocs\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Routed by its slug, as a screenshot of its edit page must be.
 */
class Post extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
