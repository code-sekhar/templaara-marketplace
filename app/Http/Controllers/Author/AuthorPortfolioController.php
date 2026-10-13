<?php

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Models\AuthorPortfolioItem;
use App\Services\Authors\AuthorMedia;
use App\Services\Authors\AuthorPortfolio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuthorPortfolioController extends Controller
{
    public function store(Request $request, AuthorPortfolio $portfolio): RedirectResponse
    {
        $portfolio->save($request->user(), $request->all());

        return $this->saved();
    }

    public function update(Request $request, AuthorPortfolioItem $item, AuthorPortfolio $portfolio): RedirectResponse
    {
        $portfolio->save($request->user(), $request->all(), $item);

        return $this->saved();
    }

    public function destroy(Request $request, AuthorPortfolioItem $item, AuthorPortfolio $portfolio): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0']]);
        $portfolio->delete($request->user(), $item, $data['revision']);

        return $this->saved();
    }

    public function reorder(Request $request, AuthorPortfolio $portfolio): RedirectResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'list', 'max:12'], 'items.*' => ['required', 'uuid', 'distinct'], 'revision' => ['required', 'integer', 'min:0']]);
        $portfolio->reorder($request->user(), $data['items'], $data['revision']);

        return $this->saved();
    }

    public function media(Request $request, AuthorMedia $media, ?AuthorPortfolioItem $item = null): RedirectResponse
    {
        $data = $request->validate(['purpose' => ['required', 'in:avatar,cover,portfolio'], 'revision' => ['required', 'integer', 'min:0'], 'file' => ['required', 'file']]);
        $media->upload($request->user(), $request->file('file'), $data['purpose'], $data['revision'], $item);

        return $this->saved();
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Professional portfolio updated.']);

        return back();
    }
}
