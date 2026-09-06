<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\IkontrolVersionRequest;
use App\Models\IkontrolVersion;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

class IkontrolVersionController extends Controller
{
    public function index() { return view('admin.versions.index', ['versions' => IkontrolVersion::latest()->paginate(20)]); }
    public function create() { return view('admin.versions.form', ['version' => new IkontrolVersion()]); }
    public function edit(IkontrolVersion $version) { return view('admin.versions.form', compact('version')); }

    public function store(IkontrolVersionRequest $request, AuditService $audit)
    {
        $version = DB::transaction(fn () => $this->persist(new IkontrolVersion(), $request->validated()));
        $audit->record('create_ikontrol_version', 'Versión iKontrol registrada.', $version, ['version' => $version->version]);
        return redirect()->route('versions.index')->with('success', 'Versión registrada.');
    }

    public function update(IkontrolVersionRequest $request, IkontrolVersion $version, AuditService $audit)
    {
        DB::transaction(fn () => $this->persist($version, $request->validated()));
        $audit->record('update_ikontrol_version', 'Versión iKontrol actualizada.', $version, ['version' => $version->version]);
        return redirect()->route('versions.index')->with('success', 'Versión actualizada.');
    }

    private function persist(IkontrolVersion $version, array $data): IkontrolVersion
    {
        $data['active'] = (bool) ($data['active'] ?? false);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        if ($data['source_type'] === 'git') {
            $data['active'] = false;
            $data['is_default'] = false;
        }
        if ($data['is_default']) {
            $data['active'] = true;
            IkontrolVersion::where('is_default', true)->when($version->exists, fn ($query) => $query->whereKeyNot($version->id))->update(['is_default' => false]);
        }
        $version->fill($data)->save();
        return $version;
    }
}
