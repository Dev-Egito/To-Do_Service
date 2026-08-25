<?php

namespace App\Service;

use App\Repositories\Contracts\CardRepositoryInterface;

/**
 * Serviço de consulta do Kanban (CQRS Read Model).
 * Mutações e publicação no RabbitMQ foram removidas: a escrita ocorre no Booking Service.
 */
class CardService
{
    public function __construct(
        protected CardRepositoryInterface $cardRepository
    ) {}

    public function listAll()
    {
        return $this->cardRepository->getAll();
    }

    public function findById(int $id)
    {
        return $this->cardRepository->findById($id);
    }
}
