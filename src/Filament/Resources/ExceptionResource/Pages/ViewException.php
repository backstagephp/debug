<?php

namespace Backstage\Debug\Filament\Resources\ExceptionResource\Pages;

use Backstage\Debug\Filament\Resources\ExceptionResource;
use Backstage\Debug\Models\Exception;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewException extends ViewRecord
{
    protected static string $resource = ExceptionResource::class;

    public function getTitle(): string
    {
        /** @var Exception $record */
        $record = $this->getRecord();

        return class_basename($record->exception_class);
    }

    public function getSubheading(): ?string
    {
        /** @var Exception $record */
        $record = $this->getRecord();

        return $record->message;
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
