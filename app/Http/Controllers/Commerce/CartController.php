<?php

namespace App\Http\Controllers\Commerce;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\CartRequest;
use App\Services\Commerce\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function __construct(private CartService $carts) {}

    public function index(Request $request): Response
    {
        return Inertia::render('commerce/Cart', ['cart' => $this->carts->view($request->user())]);
    }

    public function store(CartRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->carts->add($request->user(), $data['product_slug'], (int) $data['offer_id']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Package added to your cart.']);

        return to_route('commerce.cart.index');
    }

    public function destroy(Request $request, int $item): RedirectResponse
    {
        $this->carts->remove($request->user(), $item);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Selection removed.']);

        return to_route('commerce.cart.index');
    }
}
