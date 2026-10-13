<?php

namespace App\Http\Controllers;

use App\Services\Authors\PublicAuthor;
use Inertia\Inertia;
use Inertia\Response;

class PublicAuthorController extends Controller
{
    public function show(string $slug, PublicAuthor $authors): Response
    {
        return Inertia::render('marketplace/Author', $authors->page($authors->visible()->where('slug', $slug)->firstOrFail()));
    }
}
