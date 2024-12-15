<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Http\Requests\CreateBookRequest;
use App\Services\BookService;

class CreateBookController extends Controller
{
    public function __invoke(CreateBookRequest $request, BookService $service): array
    {
        return [
            'id' => $service->createPendingBook($request->input('prompt'))->id,
        ];
    }
}
