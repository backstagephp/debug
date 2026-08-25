<?php

namespace Backstage\Debug\Filament\Resources\ExceptionResource\Pages;

use Backstage\Debug\Filament\Resources\ExceptionResource;
use Filament\Resources\Pages\ListRecords;

class ListExceptions extends ListRecords
{
    protected static string $resource = ExceptionResource::class;

    public function getTitle(): string
    {
        return 'Exceptions';
    }
}
