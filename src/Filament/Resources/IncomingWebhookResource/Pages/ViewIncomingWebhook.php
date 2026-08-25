<?php

namespace Backstage\Debug\Filament\Resources\IncomingWebhookResource\Pages;

use Backstage\Debug\Filament\Resources\IncomingWebhookResource;
use Backstage\Debug\Models\IncomingWebhook;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewIncomingWebhook extends ViewRecord
{
    protected static string $resource = IncomingWebhookResource::class;

    public function getTitle(): string
    {
        /** @var IncomingWebhook $record */
        $record = $this->getRecord();

        return str($record->source)->title()->toString().' webhook';
    }

    public function getSubheading(): ?string
    {
        /** @var IncomingWebhook $record */
        $record = $this->getRecord();

        return "{$record->method} {$record->path} — {$record->status}";
    }

    /**
     * @return array<int, DeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
