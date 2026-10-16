<?php

namespace Arzcode\FilamentDocs\Tests\Fixtures\Resources\Posts;

use Arzcode\FilamentDocs\Tests\Fixtures\Post;
use Arzcode\FilamentDocs\Tests\Fixtures\Resources\Posts\Pages\CreatePost;
use Arzcode\FilamentDocs\Tests\Fixtures\Resources\Posts\Pages\EditPost;
use Arzcode\FilamentDocs\Tests\Fixtures\Resources\Posts\Pages\ListPosts;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;
    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('title')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListPosts::route('/'),
            'create' => CreatePost::route('/create'),
            'edit'   => EditPost::route('/{record}/edit'),
        ];
    }
}
