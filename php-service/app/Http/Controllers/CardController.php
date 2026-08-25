<?php

namespace App\Http\Controllers;

use App\Repositories\Contracts\CardRepositoryInterface;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class CardController extends Controller
{
    public function __construct(
        protected CardRepositoryInterface $cardRepository
    ) {}

    #[OA\Get(
        path: '/api/cards',
        summary: 'Lista todos os cartões',
        tags: ['Cards'],
        responses: [
            new OA\Response(response: 200, description: 'Sucesso'),
        ]
    )]
    public function index(): JsonResponse
    {
        return response()->json($this->cardRepository->getAll());
    }

    #[OA\Get(
        path: '/api/cards/{id}',
        summary: 'Exibe um cartão',
        tags: ['Cards'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Sucesso'),
            new OA\Response(response: 404, description: 'Não encontrado'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        return response()->json($this->cardRepository->findById($id));
    }
}
