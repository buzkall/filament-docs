<?php

namespace Arzcode\FilamentDocs\Tests\Fixtures\Resources\Posts\Pages;

use Arzcode\FilamentDocs\Tests\Fixtures\Resources\Posts\PostResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;
}
