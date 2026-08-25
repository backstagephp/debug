<?php

namespace Backstage\Debug\Filament\Resources\IncomingWebhookResource\Pages;

use Backstage\Debug\Filament\Resources\IncomingWebhookResource;
use Filament\Resources\Pages\ListRecords;

class ListIncomingWebhooks extends ListRecords
{
    protected static string $resource = IncomingWebhookResource::class;

    public function getTitle(): string
    {
        return 'Incoming webhooks';
    }
}
