<?php

namespace App\Http\Controllers;

use App\Repositories\Contracts\BoardRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

class BoardController extends Controller
{
    public function __construct(
        protected BoardRepositoryInterface $boardRepository
    ) {}

    #[OA\Get(
        path: '/api/boards',
        summary: 'Lista todos os quadros',
        tags: ['Boards'],
        responses: [
            new OA\Response(response: 200, description: 'Sucesso'),
        ]
    )]
    public function index(): JsonResponse
    {
        $boards = Cache::remember('boards:all', 300, function () {
            return $this->boardRepository->getAll();
        });

        return response()->json($boards);
    }

    #[OA\Get(
        path: '/api/boards/{id}',
        summary: 'Exibe um quadro',
        tags: ['Boards'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Sucesso'),
            new OA\Response(response: 404, description: 'Não encontrado'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $board = Cache::remember("board:item:{$id}", 300, function () use ($id) {
            return $this->boardRepository->findById($id);
        });

        return response()->json($board);
    }
}
