<?php
namespace App\Http\Controllers\Admin; use App\Http\Controllers\Controller; use App\Models\AdminAuditLog;
class AuditLogController extends Controller { public function __invoke(){return view('admin.audit.index',['logs'=>AdminAuditLog::with('adminUser')->latest('id')->paginate(50)]);} }
