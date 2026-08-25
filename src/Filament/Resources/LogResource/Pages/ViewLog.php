<?php

namespace Backstage\Debug\Filament\Resources\LogResource\Pages;

use Backstage\Debug\Filament\Resources\LogResource;
use Backstage\Debug\Models\Log;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLog extends ViewRecord
{
    protected static string $resource = LogResource::class;

    public function getTitle(): string
    {
        /** @var Log $record */
        $record = $this->getRecord();

        return str($record->level)->title()->toString();
    }

    public function getSubheading(): ?string
    {
        /** @var Log $record */
        $record = $this->getRecord();

        return str($record->message)->limit(120)->toString();
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
