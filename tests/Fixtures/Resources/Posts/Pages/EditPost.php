<?php

namespace Arzcode\FilamentDocs\Tests\Fixtures\Resources\Posts\Pages;

use Arzcode\FilamentDocs\Tests\Fixtures\Resources\Posts\PostResource;
use Filament\Resources\Pages\EditRecord;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;
}
