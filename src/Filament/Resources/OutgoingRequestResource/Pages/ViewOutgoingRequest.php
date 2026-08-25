<?php

namespace Backstage\Debug\Filament\Resources\OutgoingRequestResource\Pages;

use Backstage\Debug\Filament\Resources\OutgoingRequestResource;
use Backstage\Debug\Models\OutgoingRequest;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOutgoingRequest extends ViewRecord
{
    protected static string $resource = OutgoingRequestResource::class;

    public function getTitle(): string
    {
        /** @var OutgoingRequest $record */
        $record = $this->getRecord();

        return "{$record->method} {$record->host}";
    }

    public function getSubheading(): ?string
    {
        /** @var OutgoingRequest $record */
        $record = $this->getRecord();

        return $record->path;
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
