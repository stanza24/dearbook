<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Http\Resources\BookIndexResource;
use App\Services\BookService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BooksController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        return BookIndexResource::collection(
            BookService::resolve()->searchByTerm(
                term: $request->input('term'),
            ),
        );
    }
}
