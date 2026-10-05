<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialTransaction;
use Illuminate\Http\Request;

class FinancialTransactionController extends Controller
{
    
    public function index(Request $request)
    {
        $query = FinancialTransaction::with('user')->latest();

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return $query->paginate($request->get('limit', 20));
    }

    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type'           => 'required|in:income,expense',
            'amount'         => 'required|numeric|min:0',
            'payment_method' => 'required|string|in:cash,bank',
            'category'       => 'required|string',
            'description'    => 'nullable|string',
            'created_at'     => 'nullable|date',
        ]);

        $validated['user_id'] = auth()->id();
        $transaction          = FinancialTransaction::create($validated);

        if ($request->filled('created_at')) {
            $transaction->created_at = $request->created_at;
            $transaction->save();
        }

        return response()->json($transaction, 201);
    }

    
    public function show(FinancialTransaction $finance)
    {
        return $finance;
    }

    
    public function update(Request $request, FinancialTransaction $finance)
    {
        $validated = $request->validate([
            'type'           => 'required|in:income,expense',
            'amount'         => 'required|numeric|min:0',
            'payment_method' => 'required|string|in:cash,bank',
            'category'       => 'required|string',
            'description'    => 'nullable|string',
            'created_at'     => 'nullable|date',
        ]);

        $finance->update($validated);

        if ($request->filled('created_at')) {
            $finance->created_at = $request->created_at;
            $finance->save();
        }

        return $finance;
    }

    
    public function destroy(FinancialTransaction $finance)
    {
        // Системные записи (созданные автоматически другим модулем — закупкой,
        // оплатой долга, продажей и т.д.) нельзя стирать напрямую: это никак
        // не сверяется с источником (paid_amount закупки, статус долга/заказа)
        // и молча искажает финансовые отчёты. Удалять такие можно только через
        // reversal-логику владельца (например DELETE /purchases/{id}).
        // Вручную занесённые записи (trackable_id = null) удалять можно.
        if ($finance->trackable_id !== null) {
            return response()->json([
                'message' => 'Нельзя удалить транзакцию, созданную автоматически (привязана к закупке/долгу/заказу). Отмените или удалите связанный документ вместо этого.',
            ], 409);
        }

        $finance->delete();
        return response()->noContent();
    }
}
