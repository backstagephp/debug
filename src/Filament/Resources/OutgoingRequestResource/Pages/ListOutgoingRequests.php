<?php

namespace Backstage\Debug\Filament\Resources\OutgoingRequestResource\Pages;

use Backstage\Debug\Filament\Resources\OutgoingRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListOutgoingRequests extends ListRecords
{
    protected static string $resource = OutgoingRequestResource::class;

    public function getTitle(): string
    {
        return 'Outgoing requests';
    }
}
