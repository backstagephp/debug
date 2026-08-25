<?php

namespace Backstage\Debug\Filament\Resources\LogResource\Pages;

use Backstage\Debug\Filament\Resources\LogResource;
use Filament\Resources\Pages\ListRecords;

class ListLogs extends ListRecords
{
    protected static string $resource = LogResource::class;

    public function getTitle(): string
    {
        return 'Logs';
    }
}
