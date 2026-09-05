<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\FactucareSearchRequest;
use App\Services\AuditService;
use App\Services\LegacyFc2\FactucareReaderService;
use Illuminate\Http\Request;

class LegacyFactucareController extends Controller
{
    public function index()
    {
        return view('admin.legacy.factucare.index');
    }

    public function search(FactucareSearchRequest $request, FactucareReaderService $reader, AuditService $audit)
    {
        $started = microtime(true);
        $result = $reader->findUserByRfc($request->validated('rfc'));
        $audit->record('factucare_search', 'Búsqueda de usuario FactuCare.', 'factucare_user', [
            'rfc' => $request->validated('rfc'),
            'found' => $result['found'],
            'users_id' => $result['users_id'] ?? null,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);
        if (! $result['found']) {
            return back()->withInput()->with('error', $result['message']);
        }

        return redirect()->route('legacy.factucare.users.show', $result['users_id']);
    }

    public function show(int $user, Request $request, FactucareReaderService $reader, AuditService $audit)
    {
        $section = in_array($request->query('section'), ['summary', 'profile', 'clients', 'products', 'csd', 'folios', 'invoices', 'other'], true) ? $request->query('section') : 'summary';
        $search = mb_substr(trim((string) $request->query('search')), 0, 100);
        $result = $reader->getUserById($user, $section, $search ?: null, max(1, $request->integer('page', 1)));
        if (! $result['found']) {
            return redirect()->route('legacy.factucare.index')->with('error', $result['message']);
        }
        $audit->record('factucare_view_user', 'Consulta de usuario FactuCare.', 'factucare_user', ['users_id' => $user, 'section' => $section]);

        return view('admin.legacy.factucare.show', compact('result'));
    }
}
