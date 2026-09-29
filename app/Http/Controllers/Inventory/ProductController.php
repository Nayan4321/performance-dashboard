<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with('category')
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('sku', 'like', "%$s%")))
            ->orderBy('name')->paginate(30)->withQueryString();

        return view('inventory.products.index', compact('products'));
    }

    public function create()
    {
        return view('inventory.products.form', ['product' => new Product(['is_active' => true, 'unit' => 'pcs']), 'categories' => ProductCategory::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        Product::create($this->validated($request));

        return redirect()->route('inventory.products.index')->with('status', 'Product added.');
    }

    public function edit(Product $product)
    {
        return view('inventory.products.form', ['product' => $product, 'categories' => ProductCategory::orderBy('name')->get()]);
    }

    public function update(Request $request, Product $product)
    {
        $product->update($this->validated($request, $product));

        return redirect()->route('inventory.products.index')->with('status', 'Product saved.');
    }

    /** Stock on hand per branch. Everyone with inventory access can view; managers can edit. */
    public function stock(Request $request)
    {
        $user = $request->user();
        $seeAll = $user->seesEverything() || $user->can('inventory.orders.view-all');
        $branches = Branch::where('is_active', true)->when(! $seeAll, fn ($q) => $q->whereKey($user->branch_id))->orderByDesc('is_warehouse')->orderBy('name')->get();
        $products = Product::where('is_active', true)->orderBy('name')->get();
        $stock = BranchStock::whereIn('branch_id', $branches->pluck('id'))->get()->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('quantity', 'branch_id'));

        return view('inventory.stock', compact('branches', 'products', 'stock'));
    }

    public function updateStock(Request $request)
    {
        foreach ((array) $request->input('stock', []) as $productId => $perBranch) {
            foreach ((array) $perBranch as $branchId => $qty) {
                if ($qty === null || $qty === '') {
                    continue;
                }
                BranchStock::updateOrCreate(['branch_id' => (int) $branchId, 'product_id' => (int) $productId], ['quantity' => (float) $qty]);
            }
        }

        return back()->with('status', 'Stock levels saved.');
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:60', Rule::unique('products')->ignore($product?->id)],
            'name' => 'required|string|max:160',
            'unit' => 'required|string|max:20',
            'unit_price' => 'required|numeric|min:0',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'category' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:2000',
        ]);
        $data['product_category_id'] = filled($data['category'] ?? null) ? ProductCategory::firstOrCreate(['name' => trim($data['category'])])->id : null;
        unset($data['category']);

        return $data + ['is_active' => $request->boolean('is_active')];
    }
}
