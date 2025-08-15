<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BonusOperationRequest;
use App\Http\Requests\Admin\UpdateBonusSettingsRequest;
use App\Models\BonusException;
use App\Models\BonusSetting;
use App\Models\BonusTransaction;
use App\Models\Role;
use App\Models\User;
use App\Resources\BonusTransactionResource;
use App\Services\BonusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class BonusesController extends Controller
{
    private BonusService $bonus_service;

    public function __construct(BonusService $bonus_service)
    {
        $this->bonus_service = $bonus_service;
    }

    public function index()
    {
        if (!Gate::allows('access-section', 'users')) {
            abort(403, 'Доступ запрещен');
        }

        $categories = [
            'director'      => 'Директора',
            'leader'        => 'Руководители',
            'deputy_leader' => 'Заместители руководителей',
            'specialist'    => 'Специалисты',
            'warehouse'     => 'Склад'
        ];

        $settings = BonusSetting::all()->groupBy('category');
        $users = User::query()
            ->whereNotIn('role_id',[Role::MATERNITY_LEAVE,Role::FIRED])
            ->get();

        $exceptions = BonusException::with('user')->get();

        return Inertia::render('admin/Bonuses', [
            'categories'    => $categories,
            'settings'      => $settings,
            'users'         => $users,
            'exceptions'    => $exceptions,
        ]);
    }

    public function storeException(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id|unique:bonus_exceptions,user_id',
            'bonus_type' => 'required|in:director,leader,deputy_leader,specialist,warehouse'
        ]);

        BonusException::create($request->only(['user_id', 'bonus_type']));

        return redirect()->back();
    }

    public function deleteException($id)
    {
        BonusException::findOrFail($id)->delete();
        return redirect()->back();
    }

    public function transactions(Request $request)
    {
        if (!Gate::allows('access-section', 'users')) {
            abort(403, 'Доступ запрещен');
        }

        return response()->json($this->bonus_service->getRepository()->getTransactinsWithFilters($request));
    }

    public function performOperation(BonusOperationRequest $request)
    {
        $validated = $request->validated();
        $imagePath = null;

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('bonus-images', 'public');
        }

        if ($validated['operation_type'] === 'mass') {
            $this->bonus_service->performMassOperation(
                $validated['amount'],
                $validated['type'],
                $validated['description'] ?? '',
                $imagePath,
                Auth::id()
            );
        } else {
            $this->bonus_service->performUserOperation(
                User::query()->find($validated['user_id']),
                $validated['amount'],
                $validated['type'],
                $validated['description'] ?? '',
                $imagePath,
                Auth::id()
            );
        }

        return redirect()->back()->with('success', 'Операция выполнена успешно');
    }

    public function updateSettings(UpdateBonusSettingsRequest $request)
    {
        $this->bonus_service->updateSettings($request->validated());
        return redirect()->back()->with('success', 'Настройки обновлены');
    }

    public function applyMonthlyBonuses()
    {
        $this->bonus_service->applyMonthlyBonuses(Auth::id());
        return redirect()->back()->with('success', 'Ежемесячные бонусы начислены');
    }
}
