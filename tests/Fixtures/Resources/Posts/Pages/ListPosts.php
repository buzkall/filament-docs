<?php

namespace Arzcode\FilamentDocs\Tests\Fixtures\Resources\Posts\Pages;

use Arzcode\FilamentDocs\Tests\Fixtures\Resources\Posts\PostResource;
use Filament\Resources\Pages\ListRecords;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;
}
